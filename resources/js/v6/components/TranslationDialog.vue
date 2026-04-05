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
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { pollJobs } from '../../core/polling'
import { triggerTranslation } from '../../core/api'
import { normalizeApiError } from '../../core/errors'
import type { NormalizedError } from '../../core/errors'
import type { LocaleJobState, SiteMeta, SiteDescriptor, TranslationJob } from '../../core/types'

declare function __(key: string, replacements?: Record<string, string | number>): string

const t = (key: string, replacements: Record<string, string | number> = {}): string =>
  __('content-translator::messages.' + key, replacements)

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

/** Whether a translation dispatch is in progress. */
const isTranslating = ref(false)

watch(selectedSource, () => {
  if (isTranslating.value) return
  syncSelectedLocales()
})

watch(overwrite, (enabled) => {
  if (isTranslating.value || enabled) return

  const blocked = new Set(targetSites.value.filter((site) => hasExistingTranslation(site)).map((site) => site.handle))
  selectedLocales.value = selectedLocales.value.filter((handle) => !blocked.has(handle))
})

syncSelectedLocales()

/** Per-locale job tracking. */
const localeState = reactive<Record<string, LocaleJobState>>({})

/** Stop function returned by pollJobs — called on unmount / cancel. */
let stopPolling: (() => void) | null = null

// ── Computed status ───────────────────────────────────────────────────────────

/** True when every selected locale has reached a terminal state. */
const allDone = computed(
  () =>
    isTranslating.value &&
    selectedLocales.value.every((handle) => {
      const state = localeState[handle]
      return Boolean(state) && state.completedCount >= state.totalCount
    }),
)

/** True when at least one locale has failed. */
const hasFailed = computed(() => Object.values(localeState).some((s) => s.status === 'failed'))

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

// ── Actions ───────────────────────────────────────────────────────────────────

function cancel(): void {
  stopPolling?.()
  emit('close')
}

function applyJobSnapshot(jobs: TranslationJob[]): void {
  for (const [handle, state] of Object.entries(localeState)) {
    const relatedJobs = jobs.filter((job) => state.jobIds.includes(job.id))

    if (relatedJobs.length === 0) continue

    const completedCount = relatedJobs.filter((job) => job.status === 'completed').length
    const failedJobs = relatedJobs.filter((job) => job.status === 'failed')
    const terminalCount = completedCount + failedJobs.length
    const hasRunning = relatedJobs.some((job) => job.status === 'running')
    const hasPending = relatedJobs.some((job) => job.status === 'pending')

    let nextStatus: LocaleJobState['status'] = 'pending'
    const normalizedFailedJob = failedJobs[0] ? normalizeApiError(failedJobs[0].error) : null

    if (failedJobs.length > 0) {
      nextStatus = 'failed'
    } else if (terminalCount === relatedJobs.length) {
      nextStatus = 'completed'
    } else if (hasRunning) {
      nextStatus = 'running'
    } else if (hasPending) {
      nextStatus = 'pending'
    }

    localeState[handle] = {
      ...state,
      status: nextStatus,
      error: normalizedFailedJob?.message ?? null,
      errorCode: normalizedFailedJob?.code ?? null,
      completedCount: terminalCount,
    }
  }
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

  isTranslating.value = true
  const totalEntries = allEntryIds.value.length

  // Initialise per-locale state
  for (const handle of selectedLocales.value) {
    localeState[handle] = {
      status: 'pending',
      error: null,
      errorCode: null,
      completedCount: 0,
      totalCount: totalEntries,
      jobIds: [],
    }
  }

  const allJobIds: string[] = []

  try {
    for (const entryId of allEntryIds.value) {
      const result = await triggerTranslation({
        entryId,
        sourceSite: selectedSource.value,
        targetSites: selectedLocales.value,
        options: {
          generateSlug: generateSlug.value,
          overwrite: overwrite.value,
        },
      })

      if (!result.success) {
        console.error('[content-translator] trigger failed:', result.error)
        const normalized = normalizeApiError(result.error ?? t('error_trigger_failed'))

        // Mark affected locales as failed
        for (const handle of selectedLocales.value) {
          if (localeState[handle]) {
            localeState[handle] = {
              ...localeState[handle]!,
              status: 'failed',
              error: normalized.message,
              errorCode: normalized.code,
              completedCount: Math.min(localeState[handle]!.completedCount + 1, localeState[handle]!.totalCount),
            }
          }
        }
        continue
      }

      for (const job of result.jobs) {
        allJobIds.push(job.id)
        const handle = job.target_site
        if (localeState[handle]) {
          localeState[handle] = {
            ...localeState[handle]!,
            jobIds: [...localeState[handle]!.jobIds, job.id],
            status: 'pending',
          }
        }
      }
    }
  } catch (err) {
    console.error('[content-translator] dispatch error:', err)
    const normalized = normalizeApiError(err)

    // Mark everything as failed
    for (const handle of selectedLocales.value) {
      if (localeState[handle]) {
        localeState[handle] = {
          ...localeState[handle]!,
          status: 'failed',
          error: normalized.message,
          errorCode: normalized.code,
        }
      }
    }
    isTranslating.value = false
    return
  }

  if (allJobIds.length === 0) {
    isTranslating.value = false
    return
  }

  // Start polling
  stopPolling = pollJobs(allJobIds, (jobs) => {
    applyJobSnapshot(jobs)
  })
}

/**
 * Retry a failed locale by re-dispatching jobs for it.
 */
async function retryLocale(handle: string): Promise<void> {
  if (!localeState[handle]) return
  localeState[handle] = {
    ...localeState[handle]!,
    status: 'pending',
    error: null,
    errorCode: null,
    completedCount: 0,
    jobIds: [],
  }

  const newJobIds: string[] = []
  let lastError: NormalizedError | null = null

  for (const entryId of allEntryIds.value) {
    try {
      const result = await triggerTranslation({
        entryId,
        sourceSite: selectedSource.value,
        targetSites: [handle],
        options: {
          generateSlug: generateSlug.value,
          overwrite: true, // retry always overwrites
        },
      })
      if (result.success) {
        for (const job of result.jobs) {
          newJobIds.push(job.id)
        }
      } else {
        lastError = normalizeApiError(result.error ?? t('error_trigger_failed'))
      }
    } catch (err) {
      console.error('[content-translator] retry error:', err)
      lastError = normalizeApiError(err)
    }
  }

  if (newJobIds.length === 0) {
    if (localeState[handle]) {
      localeState[handle] = {
        ...localeState[handle]!,
        status: 'failed',
        error: lastError?.message ?? t('translation_failed'),
        errorCode: lastError?.code ?? 'unexpected_error',
      }
    }
    return
  }

  localeState[handle] = {
    ...localeState[handle]!,
    jobIds: newJobIds,
  }

  // Merge new job IDs into existing poll
  stopPolling?.()
  const existingIds = Object.values(localeState).flatMap((s) => s.jobIds)
  const merged = [...new Set([...existingIds, ...newJobIds])]
  stopPolling = pollJobs(merged, (jobs) => {
    applyJobSnapshot(jobs)
  })
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onBeforeUnmount(() => {
  stopPolling?.()
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
                        'bg-orange': (site as SiteMeta).is_stale,
                        'bg-green-600': hasExistingTranslation(site) && !(site as SiteMeta).is_stale,
                        'bg-red-500': !hasExistingTranslation(site),
                      }"
                    />
                    <span class="truncate">{{ site.name }}</span>
                  </div>
                </Checkbox>
              </div>

              <div class="flex items-center gap-1.5 shrink-0">
                <Badge
                  v-if="(site as SiteMeta).is_stale && !localeState[site.handle]"
                  size="sm"
                  color="orange"
                  :text="t('badge_outdated')"
                />
                <Badge
                  v-else-if="(site as SiteMeta).last_translated_at && !localeState[site.handle]"
                  size="sm"
                  color="blue"
                  :text="t('badge_translated')"
                />

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
