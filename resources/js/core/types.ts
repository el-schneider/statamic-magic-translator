/**
 * Core TypeScript types for the Content Translator addon.
 *
 * These types are shared between the v5 and v6 entry points and the core
 * API / polling / injection helpers.
 */

/**
 * Per-site status as surfaced by the PHP fieldtype preload().
 * Keys are snake_case to match the PHP array output directly.
 */
export interface SiteMeta {
    handle: string
    name: string
    exists: boolean
    last_translated_at: string | null
    is_stale: boolean
}

/**
 * Full meta payload returned by ContentTranslatorFieldtype::preload().
 */
export interface FieldtypePreload {
    entry_id: string | null
    current_site: string | null
    is_origin: boolean
    sites: SiteMeta[]
}

/**
 * Richer locale status representation used by the dialog and badge injection.
 * Derived from SiteMeta but with a computed `status` field.
 */
export interface LocaleStatus {
    handle: string
    name: string
    exists: boolean
    lastTranslatedAt: string | null
    isOutdated: boolean
    status: 'missing' | 'translated' | 'outdated' | 'manual'
}

/**
 * A single queued translation job returned by the API.
 */
export interface TranslationJob {
    id: string
    targetSite: string
    status: 'pending' | 'running' | 'completed' | 'failed'
    error?: string
    progress?: {
        completed: number
        total: number
    }
}

/**
 * Payload sent to POST /cp/content-translator/translate.
 */
export interface TranslationRequest {
    entryId: string | string[]
    sourceSite: string
    targetSites: string[]
    options: {
        generateSlug: boolean
        overwrite: boolean
    }
}

/**
 * A site descriptor passed from PHP bulk actions.
 */
export interface SiteDescriptor {
    handle: string
    name: string
}

/**
 * Per-locale tracking state within the dialog.
 */
export interface LocaleJobState {
    status: 'idle' | 'pending' | 'running' | 'completed' | 'failed'
    error: string | null
    /** For bulk mode: number of completed jobs for this locale. */
    completedCount: number
    /** For bulk mode: total jobs for this locale. */
    totalCount: number
    /** Job IDs assigned to this locale. */
    jobIds: string[]
}
