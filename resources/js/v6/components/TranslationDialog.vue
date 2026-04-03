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
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { pollJobs } from '../../core/polling'
import { triggerTranslation } from '../../core/api'
import type { LocaleJobState, SiteMeta, SiteDescriptor, TranslationJob } from '../../core/types'

declare function __(key: string, replacements?: Record<string, string | number>): string

const t = (key: string, replacements: Record<string, string | number> = {}): string =>
  __("content-translator::messages." + key, replacements)

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
  isBulk.value
    ? t('dialog_title_bulk', { count: allEntryIds.value.length })
    : t('dialog_title_single'),
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
              completedCount: Math.min(
                localeState[handle]!.completedCount + 1,
                localeState[handle]!.totalCount,
              ),
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
  <div class="fixed inset-0 z-[200] flex items-center justify-center">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/50" @click="cancel" />

    <!-- Dialog panel -->
    <div class="relative bg-white dark:bg-dark-550 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
      <!-- ── Header ──────────────────────────────────────────────── -->
      <div class="flex items-center justify-between px-6 py-4 border-b dark:border-dark-900">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">
          {{ dialogTitle }}
        </h2>
        <button
          type="button"
          class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 text-xl leading-none w-6 h-6 flex items-center justify-center rounded"
          @click="cancel"
        >
          &times;
        </button>
      </div>

      <!-- ── Body ───────────────────────────────────────────────── -->
      <div class="p-6 space-y-5 max-h-[65vh] overflow-y-auto">
        <!-- Source locale selector -->
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1 uppercase tracking-wide">
            {{ t('source') }}
          </label>
          <select v-model="selectedSource" :disabled="isTranslating" class="input-text w-full text-sm">
            <option v-for="site in sites" :key="site.handle" :value="site.handle">
              {{ site.name }}
            </option>
          </select>
        </div>

        <!-- Target locale rows -->
        <div class="space-y-0.5">
          <div
            v-for="site in targetSites"
            :key="site.handle"
            class="flex items-center gap-3 py-2.5 px-3 rounded-lg hover:bg-gray-50 dark:hover:bg-dark-400 transition-colors"
          >
            <!-- Checkbox -->
            <input
              :id="`ct-locale-${site.handle}`"
              v-model="selectedLocales"
              type="checkbox"
              :value="site.handle"
              :disabled="isLocaleDisabled(site)"
              class="rounded"
            />

            <!-- Site name + existing status -->
            <label :for="`ct-locale-${site.handle}`" class="flex-1 text-sm cursor-pointer select-none">
              <span>{{ site.name }}</span>
              <span v-if="(site as SiteMeta).is_stale" class="ml-2 text-xs text-amber-500">
                ⚠ {{ t('badge_outdated') }}
              </span>
              <span v-else-if="(site as SiteMeta).last_translated_at" class="ml-2 text-xs text-gray-400">✓</span>
            </label>

            <!-- Job status indicator -->
            <div v-if="localeState[site.handle]" class="flex items-center gap-1.5 shrink-0">
              <!-- Spinning for pending / running -->
              <svg
                v-if="['pending', 'running'].includes(localeState[site.handle]!.status)"
                class="w-4 h-4 animate-spin text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>

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

          <p v-if="targetSites.length === 0" class="text-sm text-gray-500 px-3 py-2">
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

        <!-- ── Options ─────────────────────────────────────────── -->
        <div class="pt-3 border-t dark:border-dark-900 space-y-2.5">
          <p class="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">
            {{ t('options') }}
          </p>

          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="generateSlug" type="checkbox" :disabled="isTranslating" class="rounded" />
            {{ t('generate_slugs') }}
          </label>

          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="overwrite" type="checkbox" :disabled="isTranslating" class="rounded" />
            {{ t('overwrite_existing') }}
          </label>
        </div>
      </div>

      <!-- ── Footer ─────────────────────────────────────────────── -->
      <div
        class="flex items-center justify-end gap-3 px-6 py-4 border-t dark:border-dark-900 bg-gray-50 dark:bg-dark-600"
      >
        <button type="button" class="btn" @click="cancel">
          {{ allDone ? t('close') : t('cancel') }}
        </button>
        <button
          type="button"
          class="btn-primary"
          :disabled="isTranslating || selectedLocales.length === 0"
          @click="translate"
        >
          <svg v-if="isTranslating && !allDone" class="w-4 h-4 mr-1.5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span v-if="isTranslating && !allDone">{{ t('translating') }}</span>
          <span v-else>{{ t('translate_selected') }}</span>
        </button>
      </div>
    </div>
  </div>
</template>
