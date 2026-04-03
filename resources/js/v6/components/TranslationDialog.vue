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
import { Button, Checkbox, Icon, Label, Modal, ModalClose, Select, Separator } from '@statamic/cms/ui'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { pollJobs } from '../../core/polling'
import { triggerTranslation } from '../../core/api'
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

const sourceOptions = computed(() =>
  props.sites.map((s) => ({ label: s.name, value: s.handle })),
)

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
      error: failedJobs[0]?.error ?? null,
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
        // Mark affected locales as failed
        for (const handle of selectedLocales.value) {
          if (localeState[handle]) {
            localeState[handle] = {
              ...localeState[handle]!,
              status: 'failed',
              error: result.error ?? t('error_trigger_failed'),
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
    // Mark everything as failed
    for (const handle of selectedLocales.value) {
      if (localeState[handle]) {
        localeState[handle] = {
          ...localeState[handle]!,
          status: 'failed',
          error: t('error_unexpected'),
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
    completedCount: 0,
    jobIds: [],
  }

  const newJobIds: string[] = []

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
      }
    } catch (err) {
      console.error('[content-translator] retry error:', err)
    }
  }

  if (newJobIds.length === 0) return

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
    <div class="space-y-4">
      <!-- Source locale selector -->
      <div>
        <Label :text="t('source')" />
        <Select
          :options="sourceOptions"
          :model-value="selectedSource"
          :disabled="isTranslating"
          @update:modelValue="selectedSource = $event"
        />
      </div>

      <!-- Target locale rows -->
      <div class="space-y-0.5">
        <div
          v-for="site in targetSites"
          :key="site.handle"
          class="flex items-center justify-between py-1.5"
        >
          <Checkbox
            :label="site.name"
            :model-value="selectedLocales.includes(site.handle)"
            :disabled="isLocaleDisabled(site)"
            align="center"
            @update:modelValue="toggleLocale(site.handle, $event)"
          />

          <!-- Stale / translated badge -->
          <div class="flex items-center gap-1.5 shrink-0 ml-2">
            <span v-if="(site as SiteMeta).is_stale" class="text-xs text-amber-500">
              ⚠ {{ t('badge_outdated') }}
            </span>
            <span v-else-if="(site as SiteMeta).last_translated_at" class="text-xs text-gray-400">✓</span>
          </div>

          <!-- Job status indicator -->
          <div v-if="localeState[site.handle]" class="flex items-center gap-1.5 shrink-0 ml-2">
            <!-- Spinning for pending / running -->
            <Icon
              v-if="['pending', 'running'].includes(localeState[site.handle]!.status)"
              name="loading"
              class="text-blue-500"
            />

            <!-- Completed -->
            <span v-if="localeState[site.handle]!.status === 'completed'" class="text-green-600 font-bold">✓</span>

            <!-- Failed -->
            <template v-if="localeState[site.handle]!.status === 'failed'">
              <span class="text-red-500">⚠</span>
              <button
                type="button"
                class="text-xs text-blue-600 dark:text-blue-400 underline hover:no-underline"
                @click="retryLocale(site.handle)"
              >
                {{ t('retry') }}
              </button>
            </template>

            <!-- Bulk progress counter -->
            <span v-if="isBulk && localeState[site.handle]!.totalCount > 1" class="text-xs text-gray-500">
              {{ localeState[site.handle]!.completedCount }}/{{ localeState[site.handle]!.totalCount }}
            </span>
          </div>
        </div>

        <p v-if="targetSites.length === 0" class="text-sm text-gray-500 py-2">
          {{ t('no_target_sites') }}
        </p>
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

      <!-- Options -->
      <Separator />
      <div class="space-y-2">
        <p class="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">
          {{ t('options') }}
        </p>
        <Checkbox
          :label="t('generate_slugs')"
          v-model="generateSlug"
          :disabled="isTranslating"
        />
        <Checkbox
          :label="t('overwrite_existing')"
          v-model="overwrite"
          :disabled="isTranslating"
        />
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-3 pt-3 pb-1">
        <ModalClose>
          <Button
            variant="ghost"
            :text="allDone ? t('close') : t('cancel')"
            @click="cancel"
          />
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
