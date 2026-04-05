/**
 * Magic Translator — Statamic v5 entry point (Vue 2 / Options API).
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
import { markCurrent } from '../core/api'
import {
  injectBadges,
  injectTranslateButton,
  removeBadges,
  removeTranslateButtons,
  wasPreviouslyInjected,
  wasTranslateButtonPreviouslyInjected,
} from '../core/injection'
import { getMarkedHandles, markSiteCurrent, subscribeMarked } from '../core/markCurrentStore'
import {
  getSession,
  resumeSessionIfStuck,
  retryLocale as retryLocaleInStore,
  sessionKey,
  startTranslation,
  subscribe,
  type TranslationSession,
} from '../core/store'
import type { FieldtypePreload, LocaleJobState, SiteDescriptor, SiteMeta } from '../core/types'

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
  return __('magic-translator::messages.' + key, replacements)
}

// ─────────────────────────────────────────────────────────────────────────────
// TranslationDialog — Vue 2 component definition
// ─────────────────────────────────────────────────────────────────────────────

interface DialogData {
  selectedSource: string
  selectedLocales: string[]
  generateSlug: boolean
  overwrite: boolean
  session: TranslationSession | null
  unsubscribeFn: (() => void) | null
  markedCurrentHandles: string[]
  markCurrentPending: Record<string, boolean>
  markCurrentErrors: Record<string, string>
  unsubscribeMarked: (() => void) | null
}

const TranslationDialog = {
  name: 'MagicTranslatorDialog',

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
      session: null,
      unsubscribeFn: null,
      markedCurrentHandles: [],
      markCurrentPending: {},
      markCurrentErrors: {},
      unsubscribeMarked: null,
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

    translationSessionKey(): string {
      const self = this as unknown as { allEntryIds: string[] }
      return sessionKey(self.allEntryIds)
    },

    singleEntryId(): string | null {
      const self = this as unknown as { allEntryIds: string[] }
      return self.allEntryIds.length === 1 ? (self.allEntryIds[0] ?? null) : null
    },

    localeState(): Record<string, LocaleJobState> {
      const self = this as unknown as { session: TranslationSession | null }
      return self.session?.localeState ?? {}
    },

    isTranslating(): boolean {
      const self = this as unknown as { session: TranslationSession | null }
      return self.session?.isTranslating ?? false
    },

    allDone(): boolean {
      const self = this as unknown as { session: TranslationSession | null }
      return self.session?.isComplete ?? false
    },

    hasFailed(): boolean {
      const self = this as unknown as { session: TranslationSession | null }
      return self.session?.hasFailed ?? false
    },
  },

  created() {
    const self = this as unknown as {
      translationSessionKey: string
      applySessionSnapshot: (session: TranslationSession | null) => void
      subscribeToSession: () => void
      syncSelectedLocales: () => void
      singleEntryId: string | null
      markedCurrentHandles: string[]
      unsubscribeMarked: (() => void) | null
    }

    self.applySessionSnapshot(getSession(self.translationSessionKey))
    self.subscribeToSession()
    resumeSessionIfStuck(self.translationSessionKey)

    const existing = getSession(self.translationSessionKey)
    if (!existing || !existing.isTranslating) {
      self.syncSelectedLocales()
    }

    const id = self.singleEntryId
    if (id !== null) {
      self.markedCurrentHandles = [...getMarkedHandles(id)]
      self.unsubscribeMarked = subscribeMarked(id, () => {
        self.markedCurrentHandles = [...getMarkedHandles(id)]
      })
    }
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
    const self = this as unknown as {
      unsubscribeFn: (() => void) | null
      unsubscribeMarked: (() => void) | null
    }
    self.unsubscribeFn?.()
    self.unsubscribeMarked?.()
  },

  methods: {
    t(key: string, replacements: Record<string, string | number> = {}): string {
      return __('magic-translator::messages.' + key, replacements)
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

    isMarkedCurrent(handle: string): boolean {
      const self = this as unknown as { markedCurrentHandles: string[] }
      return self.markedCurrentHandles.includes(handle)
    },

    isEffectivelyStale(site: SiteMeta | SiteDescriptor): boolean {
      const self = this as unknown as { isMarkedCurrent: (handle: string) => boolean }
      if (self.isMarkedCurrent(site.handle)) return false

      return Boolean((site as SiteMeta).is_stale)
    },

    hasEffectiveTranslation(site: SiteMeta | SiteDescriptor): boolean {
      const self = this as unknown as { isMarkedCurrent: (handle: string) => boolean }
      if (self.isMarkedCurrent(site.handle)) return true

      return Boolean((site as SiteMeta).last_translated_at)
    },

    shouldShowMarkCurrentButton(site: SiteMeta | SiteDescriptor): boolean {
      const self = this as unknown as {
        allEntryIds: string[]
        localeState: Record<string, LocaleJobState>
        hasExistingTranslation: (site: SiteMeta | SiteDescriptor) => boolean
        isMarkedCurrent: (handle: string) => boolean
        isEffectivelyStale: (site: SiteMeta | SiteDescriptor) => boolean
        hasEffectiveTranslation: (site: SiteMeta | SiteDescriptor) => boolean
      }

      if (self.allEntryIds.length !== 1) return false
      if (self.localeState[site.handle]) return false
      if (!self.hasExistingTranslation(site)) return false
      if (self.isMarkedCurrent(site.handle)) return false

      return self.isEffectivelyStale(site) || !self.hasEffectiveTranslation(site)
    },

    async handleMarkCurrentClick(handle: string) {
      const self = this as unknown as {
        allEntryIds: string[]
        markCurrentPending: Record<string, boolean>
        markCurrentErrors: Record<string, string>
      }

      const entryId = self.allEntryIds[0]
      if (!entryId) return

      self.markCurrentPending = {
        ...self.markCurrentPending,
        [handle]: true,
      }

      self.markCurrentErrors = {
        ...self.markCurrentErrors,
        [handle]: '',
      }

      try {
        const response = await markCurrent(entryId, handle)

        if (!response.success) {
          const message = response.error?.message ?? t('mark_current_failed')
          self.markCurrentErrors = {
            ...self.markCurrentErrors,
            [handle]: message,
          }

          console.error('[magic-translator] Mark current failed:', response.error ?? response)
          return
        }

        markSiteCurrent(entryId, handle)

        Statamic.$toast.success(t('mark_current_success'))
      } catch (error) {
        const message =
          error && typeof error === 'object' && 'message' in error ? String(error.message) : t('mark_current_failed')

        self.markCurrentErrors = {
          ...self.markCurrentErrors,
          [handle]: message,
        }

        console.error('[magic-translator] Mark current request failed:', error)
      } finally {
        self.markCurrentPending = {
          ...self.markCurrentPending,
          [handle]: false,
        }
      }
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

    applySessionSnapshot(session: TranslationSession | null) {
      const self = this as unknown as {
        session: TranslationSession | null
        selectedSource: string
        selectedLocales: string[]
        generateSlug: boolean
        overwrite: boolean
      }

      self.session = session

      if (!session) return

      self.selectedSource = session.sourceSite
      self.selectedLocales = [...session.selectedLocales]
      self.generateSlug = session.options.generateSlug
      self.overwrite = session.options.overwrite
    },

    subscribeToSession() {
      const self = this as unknown as {
        translationSessionKey: string
        unsubscribeFn: (() => void) | null
        applySessionSnapshot: (session: TranslationSession | null) => void
      }

      self.unsubscribeFn?.()
      self.unsubscribeFn = subscribe(self.translationSessionKey, (session) => {
        self.applySessionSnapshot(session)
      })
    },

    cancel() {
      const self = this as unknown as { $emit: (event: string) => void }
      self.$emit('close')
    },

    async translate() {
      const self = this as unknown as {
        selectedLocales: string[]
        isTranslating: boolean
        allEntryIds: string[]
        selectedSource: string
        generateSlug: boolean
        overwrite: boolean
      }

      if (!self.selectedLocales.length || self.isTranslating) return

      await startTranslation({
        entryIds: self.allEntryIds,
        sourceSite: self.selectedSource,
        selectedLocales: self.selectedLocales,
        options: {
          generateSlug: self.generateSlug,
          overwrite: self.overwrite,
        },
      })
    },

    async retryLocale(handle: string) {
      const self = this as unknown as {
        translationSessionKey: string
      }

      await retryLocaleInStore(self.translationSessionKey, handle)
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
                                        'bg-orange': isEffectivelyStale(site),
                                        'bg-green-600': hasExistingTranslation(site) && !isEffectivelyStale(site),
                                        'bg-red-500': !hasExistingTranslation(site)
                                    }"
                                ></span>

                                {{ site.name }}
                            </label>

                            <div v-if="!localeState[site.handle]" class="flex items-center gap-2 shrink-0">
                                <button
                                    v-if="shouldShowMarkCurrentButton(site)"
                                    type="button"
                                    class="text-2xs text-blue underline hover:no-underline disabled:opacity-60 disabled:no-underline"
                                    :disabled="Boolean(markCurrentPending[site.handle])"
                                    @click="handleMarkCurrentClick(site.handle)"
                                >
                                    {{ t('mark_current_button') }}
                                </button>

                                <span
                                    v-if="isEffectivelyStale(site)"
                                    class="badge-sm bg-orange dark:bg-orange-dark"
                                >
                                    {{ t('badge_outdated') }}
                                </span>

                                <span
                                    v-else-if="hasEffectiveTranslation(site)"
                                    class="badge-sm bg-blue dark:bg-dark-blue-175"
                                >
                                    {{ t('badge_translated') }}
                                </span>

                                <span v-if="markCurrentErrors[site.handle]" class="text-2xs text-red-600 dark:text-red-400">
                                    {{ markCurrentErrors[site.handle] }}
                                </span>
                            </div>

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
  buttonInjected: boolean
  observer: MutationObserver | null
  injecting: boolean
  markedCurrentHandles: string[]
  unsubscribeMarked: (() => void) | null
}

const TranslatorFieldtype = {
  name: 'MagicTranslatorFieldtype',

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
      buttonInjected: wasTranslateButtonPreviouslyInjected(),
      observer: null,
      injecting: false,
      markedCurrentHandles: [],
      unsubscribeMarked: null,
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

    effectiveSites(): SiteMeta[] {
      const self = this as unknown as { sites: SiteMeta[]; markedCurrentHandles: string[] }
      return self.sites.map((site) =>
        self.markedCurrentHandles.includes(site.handle)
          ? { ...site, is_stale: false, last_translated_at: new Date().toISOString() }
          : site,
      )
    },
  },

  watch: {
    effectiveSites: {
      handler() {
        const self = this as unknown as { tryInjectBadges: () => void }
        self.tryInjectBadges()
      },
      deep: true,
    },
  },

  mounted() {
    const self = this as unknown as {
      tryInjectBadges: () => void
      hideFieldLabelChrome: () => void
      hideEntireField: () => void
      badgeInjected: boolean
      buttonInjected: boolean
      observer: MutationObserver | null
      injecting: boolean
      hasTargets: boolean
      hasInjectedBadgesInDom: () => boolean
      hasInjectedTranslateButtonInDom: () => boolean
    }

    if (!self.hasTargets) {
      self.hideEntireField()
      return
    }

    self.hideFieldLabelChrome()
    self.tryInjectBadges()

    self.observer = new MutationObserver(() => {
      if (self.injecting) return
      if (
        self.badgeInjected &&
        self.hasInjectedBadgesInDom() &&
        self.buttonInjected &&
        self.hasInjectedTranslateButtonInDom()
      ) {
        return
      }
      self.tryInjectBadges()
    })
    self.observer.observe(document.body, { childList: true, subtree: true })

    const entryIdForSub = (this as unknown as { entryId: string | null }).entryId
    if (entryIdForSub !== null) {
      const selfForSub = this as unknown as {
        markedCurrentHandles: string[]
        unsubscribeMarked: (() => void) | null
      }
      selfForSub.markedCurrentHandles = [...getMarkedHandles(entryIdForSub)]
      selfForSub.unsubscribeMarked = subscribeMarked(entryIdForSub, () => {
        selfForSub.markedCurrentHandles = [...getMarkedHandles(entryIdForSub)]
      })
    }
  },

  beforeDestroy() {
    const self = this as unknown as {
      observer: MutationObserver | null
      unsubscribeMarked: (() => void) | null
    }
    self.observer?.disconnect()
    if (self.unsubscribeMarked) {
      self.unsubscribeMarked()
      self.unsubscribeMarked = null
    }
    removeBadges()
    removeTranslateButtons()
  },

  methods: {
    t(key: string, replacements: Record<string, string | number> = {}): string {
      return __('magic-translator::messages.' + key, replacements)
    },

    hideFieldLabelChrome() {
      const self = this as unknown as { $el: Element }

      const wrappers = [self.$el.closest('[data-ui-input-group]'), self.$el.closest('.publish-field')].filter(
        (el): el is Element => el !== null,
      )

      for (const wrapper of wrappers) {
        wrapper.querySelectorAll('[data-ui-field-header], [data-ui-field-text], .publish-field-label').forEach((el) => {
          ;(el as HTMLElement).style.display = 'none'
        })
      }
    },

    hideEntireField() {
      const self = this as unknown as { $el: Element }

      const wrappers = [self.$el.closest('[data-ui-input-group]'), self.$el.closest('.publish-field')].filter(
        (el): el is Element => el !== null,
      )

      for (const wrapper of wrappers) {
        ;(wrapper as HTMLElement).style.display = 'none'
      }
    },

    hasInjectedBadgesInDom(): boolean {
      return document.querySelector('[data-ct-badge]') !== null
    },

    hasInjectedTranslateButtonInDom(): boolean {
      return document.querySelector('[data-ct-translate-button]') !== null
    },

    tryInjectBadges() {
      const self = this as unknown as {
        effectiveSites: SiteMeta[]
        badgeInjected: boolean
        buttonInjected: boolean
        observer: MutationObserver | null
        injecting: boolean
        hasTargets: boolean
        openDialog: () => void
      }
      if (self.injecting || !self.effectiveSites.length) return

      self.injecting = true
      try {
        self.badgeInjected = injectBadges(self.effectiveSites, 'v5')
        self.buttonInjected = injectTranslateButton(self.effectiveSites, 'v5', {
          onClick: () => self.openDialog(),
          disabled: !self.hasTargets,
        })
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
        effectiveSites: SiteMeta[]
      }

      if (!self.entryId) {
        console.warn('[magic-translator] Cannot open dialog: entry_id not available.')
        return
      }

      // Pick the default source, preferring origin but falling back to the
      // current site if the user has no access to origin. `sites` is already
      // filtered server-side to the user's accessible sites.
      const accessibleHandles = new Set(self.sites.map((s) => s.handle))
      const defaultSource =
        (self.originSite && accessibleHandles.has(self.originSite) ? self.originSite : null) ??
        (self.currentSite && accessibleHandles.has(self.currentSite) ? self.currentSite : null) ??
        self.sites[0]?.handle ??
        ''

      const dialog = Statamic.$components.append('magic-translator-dialog', {
        props: {
          entryId: self.entryId,
          sourceSite: defaultSource,
          sites: self.effectiveSites,
        },
      })

      dialog.on('close', () => {
        dialog.destroy()
      })
    },
  },

  template: /* html */ `
        <div class="magic-translator-fieldtype">
            <template v-if="hasTargets">
                <button
                    v-if="!buttonInjected"
                    type="button"
                    class="btn btn-sm w-full"
                    :disabled="!hasTargets"
                    @click="openDialog"
                >
                    {{ t('translate_button') }}
                </button>

                <!-- Fallback status list when badge injection has not succeeded yet -->
                <div v-if="!badgeInjected && effectiveSites.length > 0" class="mt-3 space-y-1">
                    <div v-for="site in effectiveSites" :key="site.handle" class="text-xs flex items-center gap-1.5 py-0.5">
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
            </template>
        </div>
    `,
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

Statamic.booting(() => {
  // Register the fieldtype (auto-injected into blueprints by ServiceProvider)
  Statamic.$components.register('magic_translator-fieldtype', TranslatorFieldtype)

  // Register the dialog component (opened via $components.append)
  Statamic.$components.register('magic-translator-dialog', TranslationDialog)

  // Wire up the bulk-action callback
  // PHP TranslateEntryAction::run() calls: Statamic.$callbacks.call('openTranslationDialog', entryIds, sites)
  Statamic.$callbacks.add('openTranslationDialog', (entryIds: unknown, sites: unknown) => {
    const ids = Array.isArray(entryIds) ? (entryIds as string[]) : []
    const siteList = Array.isArray(sites) ? (sites as SiteDescriptor[]) : []

    if (ids.length === 0) return

    const dialog = Statamic.$components.append('magic-translator-dialog', {
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
