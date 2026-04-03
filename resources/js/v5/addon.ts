/**
 * Content Translator — Statamic v5 entry point (Vue 2 / Options API).
 *
 * Vue 2 SFCs are NOT used here — components are defined as plain TypeScript
 * objects whose `template` strings are compiled at runtime by the Vue 2
 * template compiler bundled with Statamic v5. This keeps the build simple
 * (no @vitejs/plugin-vue2 dependency) while still allowing full component
 * logic.
 *
 * Component registration uses `Statamic.$components.register()` which
 * delegates to `Vue.component()` under the hood.
 */
import type { Axios } from 'axios'
import { triggerTranslation } from '../core/api'
import { injectBadges, removeBadges, wasPreviouslyInjected } from '../core/injection'
import { pollJobs } from '../core/polling'
import type { FieldtypePreload, LocaleJobState, SiteDescriptor, SiteMeta, TranslationJob } from '../core/types'

declare global {
  const Statamic: {
    $axios: Axios
    $toast: {
      success: (msg: string) => void
      error: (msg: string) => void
      info: (msg: string) => void
    }
    $components: {
      register: (name: string, component: unknown) => void
      append: (
        name: string,
        options: { props: Record<string, unknown> },
      ) => {
        on: (event: string, handler: (...args: unknown[]) => void) => void
        destroy: () => void
      }
    }
    $callbacks: {
      add: (name: string, callback: (...args: unknown[]) => void) => void
    }
    booting: (callback: () => void) => void
  }

  /** Vue 2 constructor provided globally by Statamic v5. */
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const Vue: any

  function __(key: string, replacements?: Record<string, string | number>): string
}

function t(key: string, replacements: Record<string, string | number> = {}): string {
  return __('content-translator::messages.' + key, replacements)
}

// ─────────────────────────────────────────────────────────────────────────────
// TranslationDialog — Vue 2 component definition
// ─────────────────────────────────────────────────────────────────────────────

interface DialogData {
  selectedSource: string
  selectedLocales: string[]
  generateSlug: boolean
  overwrite: boolean
  localeState: Record<string, LocaleJobState>
  isTranslating: boolean
  stopPollingFn: (() => void) | null
}

const TranslationDialog = {
  name: 'ContentTranslatorDialog',

  props: {
    /** Single entry ID (single-entry mode). */
    entryId: { type: String, default: null },
    /** Array of entry IDs (bulk mode). */
    entryIds: { type: Array as () => string[], default: () => [] },
    /** Default source site handle. */
    sourceSite: { type: String, required: true },
    /** Available sites (SiteMeta | SiteDescriptor). */
    sites: { type: Array as () => Array<SiteMeta | SiteDescriptor>, required: true },
  },

  data(): DialogData {
    return {
      selectedSource: (this as unknown as { sourceSite: string }).sourceSite,
      selectedLocales: [],
      generateSlug: false,
      overwrite: false,
      localeState: {},
      isTranslating: false,
      stopPollingFn: null,
    }
  },

  computed: {
    isBulk(): boolean {
      return (this as unknown as { entryIds: string[] }).entryIds.length > 1
    },

    allEntryIds(): string[] {
      const self = this as unknown as { entryIds: string[]; entryId: string | null }
      if (self.entryIds.length > 0) return self.entryIds
      if (self.entryId) return [self.entryId]
      return []
    },

    dialogTitle(): string {
      const self = this as unknown as { isBulk: boolean; allEntryIds: string[] }
      if (self.isBulk) {
        return t('dialog_title_bulk', { count: self.allEntryIds.length })
      }
      return t('dialog_title_single')
    },

    targetSites(): Array<SiteMeta | SiteDescriptor> {
      const self = this as unknown as {
        sites: Array<SiteMeta | SiteDescriptor>
        selectedSource: string
      }
      return self.sites.filter((s) => s.handle !== self.selectedSource)
    },

    allDone(): boolean {
      const self = this as unknown as {
        isTranslating: boolean
        selectedLocales: string[]
        localeState: Record<string, LocaleJobState>
      }
      return (
        self.isTranslating &&
        self.selectedLocales.every((handle) => {
          const state = self.localeState[handle]
          return Boolean(state) && state.completedCount >= state.totalCount
        })
      )
    },

    hasFailed(): boolean {
      const self = this as unknown as { localeState: Record<string, LocaleJobState> }
      return Object.values(self.localeState).some((s) => s.status === 'failed')
    },
  },

  created() {
    ;(this as unknown as { syncSelectedLocales: () => void }).syncSelectedLocales()
  },

  watch: {
    selectedSource() {
      const self = this as unknown as {
        isTranslating: boolean
        syncSelectedLocales: () => void
      }
      if (self.isTranslating) return
      self.syncSelectedLocales()
    },

    overwrite(enabled: boolean) {
      const self = this as unknown as {
        isTranslating: boolean
        selectedLocales: string[]
        targetSites: Array<SiteMeta | SiteDescriptor>
      }

      if (self.isTranslating || enabled) return

      const blocked = new Set(
        self.targetSites.filter((site) => Boolean((site as SiteMeta).exists)).map((site) => site.handle),
      )

      self.selectedLocales = self.selectedLocales.filter((handle) => !blocked.has(handle))
    },
  },

  beforeDestroy() {
    const self = this as unknown as { stopPollingFn: (() => void) | null }
    if (self.stopPollingFn) self.stopPollingFn()
  },

  methods: {
    t(key: string, replacements: Record<string, string | number> = {}): string {
      return __('content-translator::messages.' + key, replacements)
    },

    hasExistingTranslation(site: SiteMeta | SiteDescriptor): boolean {
      return Boolean((site as SiteMeta).exists)
    },

    isLocaleDisabled(site: SiteMeta | SiteDescriptor): boolean {
      const self = this as unknown as {
        isTranslating: boolean
        overwrite: boolean
        hasExistingTranslation: (site: SiteMeta | SiteDescriptor) => boolean
      }

      return self.isTranslating || (self.hasExistingTranslation(site) && !self.overwrite)
    },

    syncSelectedLocales() {
      const self = this as unknown as {
        targetSites: Array<SiteMeta | SiteDescriptor>
        overwrite: boolean
        selectedLocales: string[]
      }

      self.selectedLocales = self.targetSites
        .filter((site) => !(site as SiteMeta).exists || self.overwrite)
        .map((site) => site.handle)
    },

    cancel() {
      const self = this as unknown as {
        stopPollingFn: (() => void) | null
        $emit: (event: string) => void
      }
      if (self.stopPollingFn) self.stopPollingFn()
      self.$emit('close')
    },

    applyJobSnapshot(jobs: TranslationJob[]) {
      const self = this as unknown as {
        localeState: Record<string, LocaleJobState>
        $set: (obj: object, key: string, val: unknown) => void
      }

      for (const [handle, state] of Object.entries(self.localeState)) {
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

        self.$set(self.localeState, handle, {
          ...state,
          status: nextStatus,
          error: failedJobs[0]?.error ?? null,
          completedCount: terminalCount,
        })
      }
    },

    async translate() {
      const self = this as unknown as {
        selectedLocales: string[]
        isTranslating: boolean
        allEntryIds: string[]
        selectedSource: string
        generateSlug: boolean
        overwrite: boolean
        localeState: Record<string, LocaleJobState>
        stopPollingFn: (() => void) | null
        $set: (obj: object, key: string, val: unknown) => void
        isBulk: boolean
      }

      if (!self.selectedLocales.length || self.isTranslating) return
      self.isTranslating = true

      const totalEntries = self.allEntryIds.length

      // Initialise per-locale state
      for (const handle of self.selectedLocales) {
        self.$set(self.localeState, handle, {
          status: 'pending',
          error: null,
          completedCount: 0,
          totalCount: totalEntries,
          jobIds: [],
        } as LocaleJobState)
      }

      const allJobIds: string[] = []

      try {
        for (const entryId of self.allEntryIds) {
          const result = await triggerTranslation({
            entryId,
            sourceSite: self.selectedSource,
            targetSites: self.selectedLocales,
            options: {
              generateSlug: self.generateSlug,
              overwrite: self.overwrite,
            },
          })

          if (!result.success) {
            for (const handle of self.selectedLocales) {
              if (self.localeState[handle]) {
                self.$set(self.localeState, handle, {
                  ...self.localeState[handle],
                  status: 'failed',
                  error: result.error ?? t('error_trigger_failed'),
                  completedCount: Math.min(
                    self.localeState[handle].completedCount + 1,
                    self.localeState[handle].totalCount,
                  ),
                })
              }
            }
            continue
          }

          for (const job of result.jobs) {
            allJobIds.push(job.id)
            const handle = job.target_site
            const existing = self.localeState[handle]
            if (existing) {
              self.$set(self.localeState, handle, {
                ...existing,
                jobIds: [...existing.jobIds, job.id],
                status: 'pending',
              })
            }
          }
        }
      } catch (err) {
        console.error('[content-translator] dispatch error:', err)
        for (const handle of self.selectedLocales) {
          if (self.localeState[handle]) {
            self.$set(self.localeState, handle, {
              ...self.localeState[handle],
              status: 'failed',
              error: t('error_unexpected'),
            })
          }
        }
        self.isTranslating = false
        return
      }

      if (allJobIds.length === 0) {
        self.isTranslating = false
        return
      }

      // Start polling
      self.stopPollingFn = pollJobs(allJobIds, (jobs) => {
        ;(self as unknown as { applyJobSnapshot: (jobs: TranslationJob[]) => void }).applyJobSnapshot(jobs)
      })
    },

    async retryLocale(handle: string) {
      const self = this as unknown as {
        localeState: Record<string, LocaleJobState>
        allEntryIds: string[]
        selectedSource: string
        generateSlug: boolean
        stopPollingFn: (() => void) | null
        $set: (obj: object, key: string, val: unknown) => void
      }

      if (!self.localeState[handle]) return

      self.$set(self.localeState, handle, {
        ...self.localeState[handle],
        status: 'pending',
        error: null,
        completedCount: 0,
        jobIds: [],
      })

      const newJobIds: string[] = []

      for (const entryId of self.allEntryIds) {
        try {
          const result = await triggerTranslation({
            entryId,
            sourceSite: self.selectedSource,
            targetSites: [handle],
            options: { generateSlug: self.generateSlug, overwrite: true },
          })
          if (result.success) {
            for (const job of result.jobs) newJobIds.push(job.id)
          }
        } catch (err) {
          console.error('[content-translator] retry error:', err)
        }
      }

      if (newJobIds.length === 0) return

      self.$set(self.localeState, handle, {
        ...self.localeState[handle],
        jobIds: newJobIds,
      })

      self.stopPollingFn?.()
      const existingIds = Object.values(self.localeState).flatMap((s) => s.jobIds)
      const merged = [...new Set([...existingIds, ...newJobIds])]

      self.stopPollingFn = pollJobs(merged, (jobs) => {
        ;(self as unknown as { applyJobSnapshot: (jobs: TranslationJob[]) => void }).applyJobSnapshot(jobs)
      })
    },
  },

  // Template is compiled at runtime by the Vue 2 compiler bundled with
  // Statamic v5. Tailwind classes and dark-mode variants are available
  // since the Statamic CP stylesheet is already loaded.
  template: /* html */ `
        <div class="fixed inset-0 z-200 flex items-center justify-center">
            <!-- Backdrop -->
            <div class="absolute inset-0" style="background:rgba(0,0,0,0.5)" @click="cancel"></div>

            <!-- Dialog panel -->
            <div class="relative bg-white dark:bg-dark-550 rounded-lg shadow-2xl w-full max-w-lg mx-4 overflow-hidden">

                <!-- Header -->
                <div class="flex items-center justify-between px-6 py-4 border-b dark:border-dark-900">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ dialogTitle }}
                    </h2>
                    <button
                        type="button"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 text-xl leading-none w-6 h-6 flex items-center justify-center"
                        @click="cancel"
                    >&times;</button>
                </div>

                <!-- Body -->
                <div class="p-6 space-y-5 max-h-[65vh] overflow-y-auto">

                    <div class="flex gap-4">
                        <!-- Source locale selector -->
                        <div class="w-1/2 space-y-2">
                            <label class="publish-field-label font-medium text-gray-800 dark:text-dark-150">
                                {{ t('source') }}
                            </label>
                            <div class="select-input-container">
                                <select
                                    v-model="selectedSource"
                                    :disabled="isTranslating"
                                    class="select-input"
                                >
                                    <option v-for="site in sites" :key="site.handle" :value="site.handle">
                                        {{ site.name }}
                                    </option>
                                </select>
                                <div class="select-input-toggle">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Options (top-right) -->
                        <div class="w-1/2 bg-gray-50 dark:bg-dark-600 rounded border border-gray-300 dark:border-dark-800 p-4 space-y-3">
                            <label class="publish-field-label font-medium text-gray-800 dark:text-dark-150">
                                {{ t('options') }}
                            </label>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2 text-sm cursor-pointer" :class="{ 'opacity-60': isTranslating }">
                                    <input v-model="generateSlug" type="checkbox" :disabled="isTranslating" class="rounded text-blue"/>
                                    {{ t('generate_slugs') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer" :class="{ 'opacity-60': isTranslating }">
                                    <input v-model="overwrite" type="checkbox" :disabled="isTranslating" class="rounded text-blue"/>
                                    {{ t('overwrite_existing') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Target locale rows -->
                    <div class="space-y-2">
                        <label class="publish-field-label font-medium text-gray-800 dark:text-dark-150">
                            {{ t('sites_panel_label') }}
                        </label>

                        <div class="border border-gray-300 dark:border-dark-800 rounded overflow-hidden">
                        <div
                            v-for="(site, index) in targetSites"
                            :key="site.handle"
                            class="text-sm flex items-center gap-3 px-4 py-2.5 border-b border-gray-200 dark:border-dark-800"
                            :class="[
                                index === targetSites.length - 1 ? 'border-b-0' : '',
                                isLocaleDisabled(site)
                                    ? 'bg-gray-300 dark:bg-dark-600 text-gray-700 dark:text-dark-150 cursor-not-allowed'
                                    : selectedLocales.includes(site.handle)
                                        ? 'bg-gray-200 dark:bg-dark-300'
                                        : 'bg-white dark:bg-dark-550 hover:bg-gray-100 dark:hover:bg-dark-500'
                            ]"
                        >
                            <input
                                :id="'ct-locale-' + site.handle"
                                v-model="selectedLocales"
                                type="checkbox"
                                :value="site.handle"
                                :disabled="isLocaleDisabled(site)"
                                class="rounded text-blue"
                                :class="{ 'opacity-50': isLocaleDisabled(site) }"
                            />

                            <label
                                :for="'ct-locale-' + site.handle"
                                class="flex-1 text-sm cursor-pointer select-none flex items-center gap-2"
                                :class="{ 'font-medium': selectedLocales.includes(site.handle) }"
                            >
                                <span
                                    class="little-dot shrink-0"
                                    :class="{
                                        'bg-orange': site.is_stale,
                                        'bg-green-600': hasExistingTranslation(site) && !site.is_stale,
                                        'bg-red-500': !hasExistingTranslation(site)
                                    }"
                                ></span>

                                {{ site.name }}

                                <span
                                    v-if="site.is_stale && !localeState[site.handle]"
                                    class="badge-sm bg-orange dark:bg-orange-dark"
                                >
                                    {{ t('badge_outdated') }}
                                </span>

                                <span
                                    v-else-if="site.last_translated_at && !localeState[site.handle]"
                                    class="badge-sm bg-blue dark:bg-dark-blue-175"
                                >
                                    {{ t('badge_translated') }}
                                </span>
                            </label>

                            <!-- Job status indicator -->
                            <div v-if="localeState[site.handle]" class="flex items-center gap-2 shrink-0">
                                <svg
                                    v-if="localeState[site.handle].status === 'pending' || localeState[site.handle].status === 'running'"
                                    class="w-4 h-4 animate-spin text-blue"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>

                                <span
                                    v-if="localeState[site.handle].status === 'completed'"
                                    class="badge-sm bg-green-600"
                                >
                                    {{ t('status_completed') }}
                                </span>

                                <template v-if="localeState[site.handle].status === 'failed'">
                                    <span class="badge-sm bg-red-500">
                                        {{ t('status_failed') }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-2xs text-blue underline hover:no-underline"
                                        @click="retryLocale(site.handle)"
                                    >{{ t('retry') }}</button>
                                </template>

                                <span
                                    v-if="isBulk && localeState[site.handle].totalCount > 1"
                                    class="text-2xs text-gray-500"
                                >
                                    {{ localeState[site.handle].completedCount }}/{{ localeState[site.handle].totalCount }}
                                </span>
                            </div>
                        </div>

                        <p v-if="targetSites.length === 0" class="text-sm text-gray-500 px-4 py-4 text-center">
                            {{ t('no_target_sites') }}
                        </p>
                    </div>
                    </div>

                    <!-- Error summary -->
                    <div
                        v-if="hasFailed"
                        class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-3 space-y-1"
                    >
                        <template v-for="(state, handle) in localeState">
                            <p v-if="state.status === 'failed'" :key="handle" class="text-xs text-red-700 dark:text-red-400">
                                <strong>{{ handle }}:</strong> {{ state.error || t('translation_failed') }}
                            </p>
                        </template>
                    </div>

                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-3 px-6 py-4 border-t dark:border-dark-900 bg-gray-50 dark:bg-dark-600">
                    <button type="button" class="btn" @click="cancel">
                        {{ allDone ? t('close') : t('cancel') }}
                    </button>
                    <button
                        type="button"
                        class="btn-primary flex items-center gap-1.5"
                        :disabled="isTranslating || !selectedLocales.length"
                        @click="translate"
                    >
                        <svg
                            v-if="isTranslating && !allDone"
                            class="w-4 h-4 animate-spin"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span v-if="isTranslating && !allDone">{{ t('translating') }}</span>
                        <span v-else>{{ t('translate_selected') }}</span>
                    </button>
                </div>
            </div>
        </div>
    `,
}

// ─────────────────────────────────────────────────────────────────────────────
// TranslatorFieldtype — Vue 2 component definition
// ─────────────────────────────────────────────────────────────────────────────

interface FieldtypeData {
  badgeInjected: boolean
  observer: MutationObserver | null
  injecting: boolean
}

const TranslatorFieldtype = {
  name: 'ContentTranslatorFieldtype',

  // Statamic injects `storeName` into all fieldtype components so they can
  // read data from the Vuex publish store if needed.
  inject: ['storeName'],

  props: {
    handle: String,
    value: null,
    meta: { type: Object as () => FieldtypePreload, required: true },
    config: Object,
  },

  data(): FieldtypeData {
    return {
      badgeInjected: wasPreviouslyInjected(),
      observer: null,
      injecting: false,
    }
  },

  computed: {
    sites(): SiteMeta[] {
      return (this as unknown as { meta: FieldtypePreload }).meta?.sites ?? []
    },

    currentSite(): string | null {
      return (this as unknown as { meta: FieldtypePreload }).meta?.current_site ?? null
    },

    originSite(): string | null {
      return (this as unknown as { meta: FieldtypePreload }).meta?.origin_site ?? null
    },

    entryId(): string | null {
      return (this as unknown as { meta: FieldtypePreload }).meta?.entry_id ?? null
    },

    targetSites(): SiteMeta[] {
      const self = this as unknown as { sites: SiteMeta[]; currentSite: string | null }
      return self.sites.filter((s) => s.handle !== self.currentSite)
    },

    hasTargets(): boolean {
      return (this as unknown as { targetSites: SiteMeta[] }).targetSites.length > 0
    },
  },

  mounted() {
    const self = this as unknown as {
      tryInjectBadges: () => void
      badgeInjected: boolean
      observer: MutationObserver | null
      injecting: boolean
      hasInjectedBadgesInDom: () => boolean
    }
    self.tryInjectBadges()

    self.observer = new MutationObserver(() => {
      if (self.injecting) return
      if (self.badgeInjected && self.hasInjectedBadgesInDom()) return
      self.tryInjectBadges()
    })
    self.observer.observe(document.body, { childList: true, subtree: true })
  },

  beforeDestroy() {
    const self = this as unknown as { observer: MutationObserver | null }
    self.observer?.disconnect()
    removeBadges()
  },

  methods: {
    t(key: string, replacements: Record<string, string | number> = {}): string {
      return __('content-translator::messages.' + key, replacements)
    },

    hasInjectedBadgesInDom(): boolean {
      return document.querySelector('[data-ct-badge]') !== null
    },

    tryInjectBadges() {
      const self = this as unknown as {
        sites: SiteMeta[]
        badgeInjected: boolean
        observer: MutationObserver | null
        injecting: boolean
      }
      if (self.injecting || !self.sites.length) return

      self.injecting = true
      try {
        const ok = injectBadges(self.sites, 'v5')
        self.badgeInjected = ok
      } finally {
        self.injecting = false
      }
    },

    openDialog() {
      const self = this as unknown as {
        entryId: string | null
        currentSite: string | null
        originSite: string | null
        sites: SiteMeta[]
      }

      if (!self.entryId) {
        console.warn('[content-translator] Cannot open dialog: entry_id not available.')
        return
      }

      const dialog = Statamic.$components.append('content-translator-dialog', {
        props: {
          entryId: self.entryId,
          sourceSite: self.originSite ?? self.currentSite ?? self.sites[0]?.handle ?? '',
          sites: self.sites,
        },
      })

      dialog.on('close', () => {
        dialog.destroy()
      })
    },
  },

  template: /* html */ `
        <div class="content-translator-fieldtype">
            <button
                type="button"
                class="btn btn-sm w-full"
                :disabled="!hasTargets"
                @click="openDialog"
            >
                {{ t('translate_button') }}
            </button>

            <!-- Fallback status list when badge injection has not succeeded yet -->
            <div v-if="!badgeInjected && sites.length > 0" class="mt-3 space-y-1">
                <div v-for="site in sites" :key="site.handle" class="text-xs flex items-center gap-1.5 py-0.5">
                    <span
                        class="little-dot shrink-0"
                        :class="{
                            'bg-green-600': site.exists && !site.is_stale,
                            'bg-orange': site.is_stale,
                            'bg-red-500': !site.exists
                        }"
                    ></span>
                    <span class="flex-1 truncate">{{ site.name }}</span>
                    <span v-if="site.is_stale" class="text-orange">⚠</span>
                    <span v-else-if="site.last_translated_at" class="text-gray-400">✓</span>
                    <span v-else class="text-gray-400">—</span>
                </div>
            </div>
        </div>
    `,
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

Statamic.booting(() => {
  // Register the fieldtype (auto-injected into blueprints by ServiceProvider)
  Statamic.$components.register('content_translator-fieldtype', TranslatorFieldtype)

  // Register the dialog component (opened via $components.append)
  Statamic.$components.register('content-translator-dialog', TranslationDialog)

  // Wire up the bulk-action callback
  // PHP TranslateEntryAction::run() calls: Statamic.$callbacks.call('openTranslationDialog', entryIds, sites)
  Statamic.$callbacks.add('openTranslationDialog', (entryIds: unknown, sites: unknown) => {
    const ids = Array.isArray(entryIds) ? (entryIds as string[]) : []
    const siteList = Array.isArray(sites) ? (sites as SiteDescriptor[]) : []

    if (ids.length === 0) return

    const dialog = Statamic.$components.append('content-translator-dialog', {
      props: {
        entryIds: ids,
        sourceSite: siteList[0]?.handle ?? '',
        sites: siteList,
      },
    })

    dialog.on('close', () => {
      dialog.destroy()
    })
  })
})
