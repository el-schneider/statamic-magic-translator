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
import { injectBadges, removeBadges, wasPreviouslyInjected } from '../core/injection'
import { pollJobs } from '../core/polling'
import { triggerTranslation } from '../core/api'
import type {
    LocaleJobState,
    SiteDescriptor,
    SiteMeta,
    FieldtypePreload,
} from '../core/types'

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
            if (self.isBulk) return `Translate ${String(self.allEntryIds.length)} entries`
            return 'Translate'
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
                    return state?.status === 'completed' || state?.status === 'failed'
                })
            )
        },

        hasFailed(): boolean {
            const self = this as unknown as { localeState: Record<string, LocaleJobState> }
            return Object.values(self.localeState).some((s) => s.status === 'failed')
        },
    },

    created() {
        // Pre-select locales that don't have an existing translation
        const self = this as unknown as {
            targetSites: Array<SiteMeta | SiteDescriptor>
            selectedLocales: string[]
            sourceSite: string
        }
        self.selectedLocales = self.targetSites
            .filter((s) => !(s as SiteMeta).exists)
            .map((s) => s.handle)
    },

    beforeDestroy() {
        const self = this as unknown as { stopPollingFn: (() => void) | null }
        if (self.stopPollingFn) self.stopPollingFn()
    },

    methods: {
        cancel() {
            const self = this as unknown as {
                stopPollingFn: (() => void) | null
                $emit: (event: string) => void
            }
            if (self.stopPollingFn) self.stopPollingFn()
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
                                    error: result.error ?? 'Trigger failed',
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
                            error: 'An unexpected error occurred.',
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
                for (const job of jobs) {
                    const handle = job.targetSite
                    const state = self.localeState[handle]
                    if (!state) continue

                    const isTerminal =
                        job.status === 'completed' || job.status === 'failed'
                    self.$set(self.localeState, handle, {
                        ...state,
                        status: isTerminal
                            ? job.status
                            : job.status,
                        error: job.error ?? null,
                        completedCount: isTerminal
                            ? state.completedCount + 1
                            : state.completedCount,
                    })
                }
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

            self.stopPollingFn?.()
            const existingIds = Object.values(self.localeState).flatMap((s) => s.jobIds)
            const merged = [...new Set([...existingIds, ...newJobIds])]

            self.stopPollingFn = pollJobs(merged, (jobs) => {
                for (const job of jobs) {
                    const h = job.targetSite
                    const state = self.localeState[h]
                    if (!state) continue
                    const isTerminal =
                        job.status === 'completed' || job.status === 'failed'
                    self.$set(self.localeState, h, {
                        ...state,
                        status: job.status,
                        error: job.error ?? null,
                        completedCount: isTerminal
                            ? state.completedCount + 1
                            : state.completedCount,
                    })
                }
            })
        },
    },

    // Template is compiled at runtime by the Vue 2 compiler bundled with
    // Statamic v5. Tailwind classes and dark-mode variants are available
    // since the Statamic CP stylesheet is already loaded.
    template: /* html */ `
        <div class="fixed inset-0 z-[200] flex items-center justify-center">
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/50" @click="cancel"></div>

            <!-- Dialog panel -->
            <div class="relative bg-white dark:bg-dark-550 rounded-lg shadow-2xl w-full max-w-md mx-4 overflow-hidden">

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

                    <!-- Source locale selector -->
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1 uppercase tracking-wide">
                            Source
                        </label>
                        <select
                            v-model="selectedSource"
                            :disabled="isTranslating"
                            class="input-text w-full text-sm"
                        >
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
                            class="flex items-center gap-3 py-2.5 px-3 rounded hover:bg-gray-50 dark:hover:bg-dark-400"
                        >
                            <input
                                :id="'ct-locale-' + site.handle"
                                v-model="selectedLocales"
                                type="checkbox"
                                :value="site.handle"
                                :disabled="isTranslating"
                                class="rounded"
                            />
                            <label
                                :for="'ct-locale-' + site.handle"
                                class="flex-1 text-sm cursor-pointer select-none"
                            >
                                {{ site.name }}
                                <span v-if="site.is_stale" class="ml-2 text-xs text-orange">⚠ outdated</span>
                                <span v-else-if="site.last_translated_at" class="ml-2 text-xs text-gray-400">✓</span>
                            </label>

                            <!-- Job status indicator -->
                            <div v-if="localeState[site.handle]" class="flex items-center gap-1.5 shrink-0">
                                <svg
                                    v-if="localeState[site.handle].status === 'pending' || localeState[site.handle].status === 'running'"
                                    class="w-4 h-4 animate-spin text-blue-500"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                <span v-if="localeState[site.handle].status === 'completed'" class="text-green-600 font-bold">✓</span>
                                <template v-if="localeState[site.handle].status === 'failed'">
                                    <span class="text-red-500">⚠</span>
                                    <button
                                        type="button"
                                        class="text-xs text-blue underline hover:no-underline"
                                        @click="retryLocale(site.handle)"
                                    >Retry</button>
                                </template>
                                <span
                                    v-if="isBulk && localeState[site.handle].totalCount > 1"
                                    class="text-xs text-gray-500"
                                >
                                    {{ localeState[site.handle].completedCount }}/{{ localeState[site.handle].totalCount }}
                                </span>
                            </div>
                        </div>

                        <p v-if="targetSites.length === 0" class="text-sm text-gray-500 px-3 py-2">
                            No target sites available.
                        </p>
                    </div>

                    <!-- Error summary -->
                    <div
                        v-if="hasFailed"
                        class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-3 space-y-1"
                    >
                        <template v-for="(state, handle) in localeState">
                            <p v-if="state.status === 'failed'" :key="handle" class="text-xs text-red-700 dark:text-red-400">
                                <strong>{{ handle }}:</strong> {{ state.error || 'Translation failed.' }}
                            </p>
                        </template>
                    </div>

                    <!-- Options -->
                    <div class="pt-3 border-t dark:border-dark-900 space-y-2.5">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">Options</p>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input v-model="generateSlug" type="checkbox" :disabled="isTranslating" class="rounded"/>
                            Generate slugs from translated title
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input v-model="overwrite" type="checkbox" :disabled="isTranslating" class="rounded"/>
                            Overwrite existing translations
                        </label>
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-3 px-6 py-4 border-t dark:border-dark-900 bg-gray-50 dark:bg-dark-600">
                    <button type="button" class="btn" @click="cancel">
                        {{ allDone ? 'Close' : 'Cancel' }}
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
                        <span v-if="isTranslating && !allDone">Translating…</span>
                        <span v-else>Translate selected</span>
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
        }
    },

    computed: {
        sites(): SiteMeta[] {
            return (this as unknown as { meta: FieldtypePreload }).meta?.sites ?? []
        },

        currentSite(): string | null {
            return (this as unknown as { meta: FieldtypePreload }).meta?.current_site ?? null
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
        }
        self.tryInjectBadges()

        if (!self.badgeInjected) {
            self.observer = new MutationObserver(() => {
                if (!self.badgeInjected) self.tryInjectBadges()
            })
            self.observer.observe(document.body, { childList: true, subtree: true })
        }
    },

    beforeDestroy() {
        const self = this as unknown as { observer: MutationObserver | null }
        self.observer?.disconnect()
        removeBadges()
    },

    methods: {
        tryInjectBadges() {
            const self = this as unknown as {
                sites: SiteMeta[]
                badgeInjected: boolean
                observer: MutationObserver | null
            }
            if (!self.sites.length) return

            const ok = injectBadges(self.sites, 'v5')
            if (ok) {
                self.badgeInjected = true
                self.observer?.disconnect()
                self.observer = null
            }
        },

        openDialog() {
            const self = this as unknown as {
                entryId: string | null
                currentSite: string | null
                sites: SiteMeta[]
            }

            if (!self.entryId) {
                console.warn('[content-translator] Cannot open dialog: entry_id not available.')
                return
            }

            const dialog = Statamic.$components.append('content-translator-dialog', {
                props: {
                    entryId: self.entryId,
                    sourceSite: self.currentSite ?? self.sites[0]?.handle ?? '',
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
                Translate
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
    Statamic.$callbacks.add(
        'openTranslationDialog',
        (entryIds: unknown, sites: unknown) => {
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
        },
    )
})
