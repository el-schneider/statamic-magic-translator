<script setup lang="ts">
/**
 * TranslationDialog — Vue 3 (Statamic v6)
 *
 * A modal dialog for dispatching entry translations. Supports both
 * single-entry mode (opened from the fieldtype sidebar button) and bulk
 * mode (opened from the "Translate" bulk action on the entries listing).
 *
 * Lifecycle:
 *  1. User selects target locales + options and clicks "Translate".
 *  2. One API call per entry dispatches jobs for all selected locales.
 *  3. Polling updates per-row status (idle → pending → running → done/failed).
 *  4. Failed rows show an inline error + Retry button.
 *  5. Dialog self-closes (emits 'close') when all jobs complete, or user cancels.
 */
import { Badge, Button, Card, Checkbox, Icon, Label, Modal, ModalClose, Select, Separator } from '@statamic/cms/ui'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { markCurrent } from '../../core/api'
import { getMarkedHandles, markSiteCurrent, subscribeMarked } from '../../core/markCurrentStore'
import {
  getSession,
  retryLocale as retryLocaleInStore,
  sessionKey,
  startTranslation,
  subscribe,
  type TranslationSession,
} from '../../core/store'
import type { LocaleJobState, SiteMeta, SiteDescriptor } from '../../core/types'

declare function __(key: string, replacements?: Record<string, string | number>): string

const t = (key: string, replacements: Record<string, string | number> = {}): string =>
  __('magic-translator::messages.' + key, replacements)

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  /** Single entry ID (single-entry mode) or array (bulk mode). */
  entryId?: string | null
  /** Array of entry IDs for bulk mode. */
  entryIds?: string[]
  /** The currently-active / default source site handle. */
  sourceSite: string
  /**
   * Site list from the fieldtype preload (single-entry mode) or from the
   * PHP bulk action (bulk mode). Only `handle` and `name` are required for
   * the dialog UI; `exists`, `last_translated_at`, `is_stale` are optional.
   */
  sites: Array<SiteMeta | SiteDescriptor>
}

const props = withDefaults(defineProps<Props>(), {
  entryId: null,
  entryIds: () => [],
})

const emit = defineEmits<{
  close: []
}>()

// ── Derived ──────────────────────────────────────────────────────────────────

/** True when opened for multiple entries at once. */
const isBulk = computed(() => props.entryIds.length > 1)

/** All IDs to translate. */
const allEntryIds = computed<string[]>(() => {
  if (props.entryIds.length > 0) return props.entryIds
  if (props.entryId) return [props.entryId]
  return []
})

/** Dialog heading. */
const dialogTitle = computed(() =>
  isBulk.value ? t('dialog_title_bulk', { count: allEntryIds.value.length }) : t('dialog_title_single'),
)

// ── State ─────────────────────────────────────────────────────────────────────

const selectedSource = ref<string>(props.sourceSite)

/** Sites available as translation targets (all sites except the selected source). */
const targetSites = computed<Array<SiteMeta | SiteDescriptor>>(() =>
  props.sites.filter((s) => s.handle !== selectedSource.value),
)

function hasExistingTranslation(site: SiteMeta | SiteDescriptor): boolean {
  const full = site as SiteMeta
  return Boolean(full.exists)
}

function isLocaleDisabled(site: SiteMeta | SiteDescriptor): boolean {
  return isTranslating.value || (hasExistingTranslation(site) && !overwrite.value)
}

function syncSelectedLocales(): void {
  const defaultSelection = targetSites.value
    .filter((site) => !hasExistingTranslation(site) || overwrite.value)
    .map((site) => site.handle)

  selectedLocales.value = [...new Set(defaultSelection)]
}

/** Locale handles that are checked in the UI. */
const selectedLocales = ref<string[]>([])

const generateSlug = ref(false)
const overwrite = ref(false)
const session = ref<TranslationSession | null>(null)
const translationSessionKey = computed(() => sessionKey(allEntryIds.value))

const localeState = computed<Record<string, LocaleJobState>>(() => session.value?.localeState ?? {})
const isTranslating = computed(() => session.value?.isTranslating ?? false)
const markCurrentPending = ref<Record<string, boolean>>({})
const markCurrentErrors = ref<Record<string, string>>({})
const singleEntryId = computed<string | null>(() =>
  allEntryIds.value.length === 1 ? (allEntryIds.value[0] ?? null) : null,
)
const markedCurrentHandles = ref<Set<string>>(singleEntryId.value ? getMarkedHandles(singleEntryId.value) : new Set())
let unsubscribeMarked: (() => void) | null = null

let unsubscribeSession: (() => void) | null = null

watch(selectedSource, () => {
  if (isTranslating.value) return
  syncSelectedLocales()
})

watch(overwrite, (enabled) => {
  if (isTranslating.value || enabled) return

  const blocked = new Set(targetSites.value.filter((site) => hasExistingTranslation(site)).map((site) => site.handle))
  selectedLocales.value = selectedLocales.value.filter((handle) => !blocked.has(handle))
})

function applySessionSnapshot(nextSession: TranslationSession | null): void {
  session.value = nextSession
  if (!nextSession) return

  selectedSource.value = nextSession.sourceSite
  selectedLocales.value = [...nextSession.selectedLocales]
  generateSlug.value = nextSession.options.generateSlug
  overwrite.value = nextSession.options.overwrite
}

function subscribeToSession(): void {
  unsubscribeSession?.()
  unsubscribeSession = subscribe(translationSessionKey.value, (nextSession) => {
    applySessionSnapshot(nextSession)
  })
}

// ── Computed status ───────────────────────────────────────────────────────────

const allDone = computed(() => session.value?.isComplete ?? false)

const hasFailed = computed(() => session.value?.hasFailed ?? false)

// ── Select options ────────────────────────────────────────────────────────────

const sourceOptions = computed(() => props.sites.map((s) => ({ label: s.name, value: s.handle })))

// ── Checkbox helpers ──────────────────────────────────────────────────────────

function toggleLocale(handle: string, checked: boolean): void {
  if (checked) {
    selectedLocales.value = [...selectedLocales.value, handle]
  } else {
    selectedLocales.value = selectedLocales.value.filter((h) => h !== handle)
  }
}

function isMarkedCurrent(handle: string): boolean {
  return markedCurrentHandles.value.has(handle)
}

function isEffectivelyStale(site: SiteMeta | SiteDescriptor): boolean {
  if (isMarkedCurrent(site.handle)) return false
  return Boolean((site as SiteMeta).is_stale)
}

function hasEffectiveTranslation(site: SiteMeta | SiteDescriptor): boolean {
  if (isMarkedCurrent(site.handle)) return true
  return Boolean((site as SiteMeta).last_translated_at)
}

function shouldShowMarkCurrentButton(site: SiteMeta | SiteDescriptor): boolean {
  if (allEntryIds.value.length !== 1) return false
  if (localeState.value[site.handle]) return false
  if (!hasExistingTranslation(site)) return false
  if (isMarkedCurrent(site.handle)) return false

  return isEffectivelyStale(site) || !hasEffectiveTranslation(site)
}

async function handleMarkCurrentClick(handle: string): Promise<void> {
  const entryId = allEntryIds.value[0]
  if (!entryId) return

  markCurrentPending.value = {
    ...markCurrentPending.value,
    [handle]: true,
  }

  markCurrentErrors.value = {
    ...markCurrentErrors.value,
    [handle]: '',
  }

  try {
    const response = await markCurrent(entryId, handle)

    if (!response.success) {
      const message = response.error?.message ?? t('mark_current_failed')
      markCurrentErrors.value = {
        ...markCurrentErrors.value,
        [handle]: message,
      }
      console.error('[magic-translator] Mark current failed:', response.error ?? response)
      return
    }

    if (singleEntryId.value) {
      markSiteCurrent(singleEntryId.value, handle)
    }

    if (typeof Statamic !== 'undefined' && Statamic.$toast) {
      Statamic.$toast.success(t('mark_current_success'))
    }
  } catch (error) {
    const message =
      error && typeof error === 'object' && 'message' in error ? String(error.message) : t('mark_current_failed')

    markCurrentErrors.value = {
      ...markCurrentErrors.value,
      [handle]: message,
    }

    console.error('[magic-translator] Mark current request failed:', error)
  } finally {
    markCurrentPending.value = {
      ...markCurrentPending.value,
      [handle]: false,
    }
  }
}

// ── Actions ───────────────────────────────────────────────────────────────────

function cancel(): void {
  emit('close')
}

/**
 * Dispatch translation jobs for all selected locales.
 *
 * In bulk mode, iterates all entry IDs and makes one API call per entry.
 * All returned job IDs are collected into per-locale buckets and polled
 * together.
 */
async function translate(): Promise<void> {
  if (!selectedLocales.value.length || isTranslating.value) return

  await startTranslation({
    entryIds: allEntryIds.value,
    sourceSite: selectedSource.value,
    selectedLocales: selectedLocales.value,
    options: {
      generateSlug: generateSlug.value,
      overwrite: overwrite.value,
    },
  })
}

/**
 * Retry a failed locale by re-dispatching jobs for it.
 */
async function retryLocale(handle: string): Promise<void> {
  await retryLocaleInStore(translationSessionKey.value, handle)
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(() => {
  const existing = getSession(translationSessionKey.value)
  applySessionSnapshot(existing)
  subscribeToSession()

  if (!existing || !existing.isTranslating) {
    syncSelectedLocales()
  }

  const id = singleEntryId.value
  if (id !== null) {
    unsubscribeMarked = subscribeMarked(id, () => {
      markedCurrentHandles.value = getMarkedHandles(id)
    })
  }
})

onBeforeUnmount(() => {
  unsubscribeSession?.()
  unsubscribeMarked?.()
})
</script>

<template>
  <Modal :open="true" :title="dialogTitle" @dismissed="cancel">
    <div class="space-y-5">
      <!-- Source + options (2 columns) -->
      <div class="flex gap-4">
        <div class="w-1/2 space-y-1.5">
          <Label :text="t('source')" />
          <Select
            :options="sourceOptions"
            :model-value="selectedSource"
            :disabled="isTranslating"
            @update:modelValue="selectedSource = $event"
          />
        </div>

        <div class="w-1/2 border border-gray-300 dark:border-dark-800 rounded p-4 space-y-2">
          <Label :text="t('options')" />
          <Checkbox :label="t('generate_slugs')" v-model="generateSlug" :disabled="isTranslating" />
          <Checkbox :label="t('overwrite_existing')" v-model="overwrite" :disabled="isTranslating" />
        </div>
      </div>

      <!-- Target locale rows -->
      <div class="space-y-2">
        <Label :text="t('sites_panel_label')" />

        <Card class="p-3! space-y-1">
          <div
            v-for="site in targetSites"
            :key="site.handle"
            class="w-full px-4 py-2 text-sm rounded-lg transition-colors"
            :class="[
              isLocaleDisabled(site)
                ? 'bg-gray-100 dark:bg-gray-800 opacity-60 cursor-not-allowed'
                : selectedLocales.includes(site.handle)
                  ? 'bg-blue-100 dark:bg-gray-700'
                  : 'hover:bg-gray-100 dark:hover:bg-gray-800',
            ]"
          >
            <div class="flex items-center justify-between gap-x-2">
              <div class="flex flex-1 min-w-0 items-center">
                <Checkbox
                  :model-value="selectedLocales.includes(site.handle)"
                  :disabled="isLocaleDisabled(site)"
                  align="center"
                  @update:modelValue="toggleLocale(site.handle, $event)"
                >
                  <div class="flex items-center min-w-0">
                    <span
                      class="little-dot me-2"
                      :class="{
                        'bg-orange': isEffectivelyStale(site),
                        'bg-green-600': hasExistingTranslation(site) && !isEffectivelyStale(site),
                        'bg-red-500': !hasExistingTranslation(site),
                      }"
                    />
                    <span class="truncate">{{ site.name }}</span>
                  </div>
                </Checkbox>
              </div>

              <div class="flex items-center gap-1.5 shrink-0">
                <Button
                  v-if="shouldShowMarkCurrentButton(site)"
                  variant="ghost"
                  size="sm"
                  :text="t('mark_current_button')"
                  :loading="Boolean(markCurrentPending[site.handle])"
                  :disabled="Boolean(markCurrentPending[site.handle])"
                  @click="handleMarkCurrentClick(site.handle)"
                />

                <Badge
                  v-if="isEffectivelyStale(site) && !localeState[site.handle]"
                  size="sm"
                  color="orange"
                  :text="t('badge_outdated')"
                />
                <Badge
                  v-else-if="hasEffectiveTranslation(site) && !localeState[site.handle]"
                  size="sm"
                  color="blue"
                  :text="t('badge_translated')"
                />

                <span v-if="markCurrentErrors[site.handle]" class="text-2xs text-red-600 dark:text-red-400">
                  {{ markCurrentErrors[site.handle] }}
                </span>

                <!-- Job status indicator -->
                <div v-if="localeState[site.handle]" class="flex items-center gap-2 shrink-0">
                  <Icon
                    v-if="['pending', 'running'].includes(localeState[site.handle]!.status)"
                    name="loading"
                    class="text-blue"
                  />

                  <Badge
                    v-if="localeState[site.handle]!.status === 'completed'"
                    size="sm"
                    color="green"
                    :text="t('status_completed')"
                  />

                  <template v-if="localeState[site.handle]!.status === 'failed'">
                    <Badge size="sm" color="red" :text="t('status_failed')" />
                    <button
                      type="button"
                      class="text-2xs text-blue underline hover:no-underline"
                      @click="retryLocale(site.handle)"
                    >
                      {{ t('retry') }}
                    </button>
                  </template>

                  <span v-if="isBulk && localeState[site.handle]!.totalCount > 1" class="text-2xs text-gray-500">
                    {{ localeState[site.handle]!.completedCount }}/{{ localeState[site.handle]!.totalCount }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <p v-if="targetSites.length === 0" class="text-sm text-gray-500 px-4 py-4 text-center">
            {{ t('no_target_sites') }}
          </p>
        </Card>
      </div>

      <!-- Error summary -->
      <div
        v-if="hasFailed"
        class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 space-y-1"
      >
        <template v-for="(state, handle) in localeState" :key="handle">
          <p v-if="state.status === 'failed'" class="text-xs text-red-700 dark:text-red-400">
            <strong>{{ handle }}:</strong>
            {{ state.error ?? t('translation_failed') }}
          </p>
        </template>
      </div>

      <Separator />
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-3 pt-3 pb-1">
        <ModalClose>
          <Button variant="ghost" :text="allDone ? t('close') : t('cancel')" @click="cancel" />
        </ModalClose>
        <Button
          variant="primary"
          :text="isTranslating && !allDone ? t('translating') : t('translate_selected')"
          :loading="isTranslating && !allDone"
          :disabled="isTranslating || selectedLocales.length === 0"
          @click="translate"
        />
      </div>
    </template>
  </Modal>
</template>
