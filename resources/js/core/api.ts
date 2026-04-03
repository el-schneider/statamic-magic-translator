/**
 * HTTP API client for the Content Translator addon.
 *
 * Uses `Statamic.$axios` which is the pre-configured Axios instance provided
 * by the Statamic CP, already set up with CSRF tokens and base URL.
 */
import type { TranslationJob, TranslationRequest } from './types'

declare const Statamic: {
    $axios: {
        post: (url: string, data?: unknown) => Promise<{ data: unknown }>
        get: (url: string, config?: { params?: unknown }) => Promise<{ data: unknown }>
    }
}

/** Shape of a job entry in the trigger response. */
interface ApiJob {
    id: string
    target_site: string
    status: string
    error?: string
}

/** Response from POST /cp/content-translator/translate */
export interface TriggerResponse {
    success: boolean
    jobs: ApiJob[]
    error?: string
}

/** Response from GET /cp/content-translator/status */
export interface StatusResponse {
    jobs: ApiJob[]
}

/**
 * Dispatch translation jobs for one or more entries.
 *
 * Returns the raw API response including job IDs keyed by target site.
 */
export async function triggerTranslation(request: TranslationRequest): Promise<TriggerResponse> {
    const entryIds = Array.isArray(request.entryId) ? request.entryId : [request.entryId]
    const response = await Statamic.$axios.post('/cp/content-translator/translate', {
        entry_id: entryIds.length === 1 ? entryIds[0] : entryIds,
        source_site: request.sourceSite,
        target_sites: request.targetSites,
        options: {
            generate_slug: request.options.generateSlug,
            overwrite: request.options.overwrite,
        },
    })
    return response.data as TriggerResponse
}

/**
 * Poll the status of a set of job IDs.
 */
export async function checkStatus(jobIds: string[]): Promise<StatusResponse> {
    const response = await Statamic.$axios.get('/cp/content-translator/status', {
        params: { jobs: jobIds },
    })
    return response.data as StatusResponse
}

/**
 * Map a raw API job object to our typed TranslationJob interface.
 */
export function mapApiJob(apiJob: ApiJob): TranslationJob {
    return {
        id: apiJob.id,
        targetSite: apiJob.target_site,
        status: apiJob.status as TranslationJob['status'],
        error: apiJob.error,
    }
}
