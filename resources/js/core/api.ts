/**
 * HTTP API client for the Content Translator addon.
 *
 * In Statamic v5, uses `Statamic.$axios` (pre-configured Axios instance with
 * CSRF tokens and base URL). In Statamic v6, falls back to native `fetch` with
 * XSRF-TOKEN cookie-based CSRF handling (v6 dropped `$axios` in favour of Inertia).
 */
import type { NormalizedError } from './errors'
import { normalizeApiError } from './errors'
import type { TranslationJob, TranslationRequest } from './types'

declare const Statamic: {
  $axios?: {
    post: (url: string, data?: unknown) => Promise<{ data: unknown }>
    get: (url: string, config?: { params?: unknown }) => Promise<{ data: unknown }>
  }
}

/** Shape of a job entry in the trigger response. */
interface ApiJob {
  id: string
  target_site: string
  status: string
  error?: string | NormalizedError
}

/** Response from POST /cp/content-translator/translate */
export interface TriggerResponse {
  success: boolean
  jobs: ApiJob[]
  error?: string | NormalizedError
}

/** Response from GET /cp/content-translator/status */
export interface StatusResponse {
  jobs: ApiJob[]
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Read the XSRF-TOKEN cookie value (URL-decoded) for use as the X-XSRF-TOKEN
 * request header in Laravel's CSRF scheme.
 */
function getXsrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

/**
 * Build common headers for all JSON requests.
 */
function jsonHeaders(): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-XSRF-TOKEN': getXsrfToken(),
  }
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Dispatch translation jobs for one or more entries.
 *
 * Returns the raw API response including job IDs keyed by target site.
 */
export async function triggerTranslation(request: TranslationRequest): Promise<TriggerResponse> {
  const entryIds = Array.isArray(request.entryId) ? request.entryId : [request.entryId]
  const payload = {
    entry_id: entryIds.length === 1 ? entryIds[0] : entryIds,
    source_site: request.sourceSite,
    target_sites: request.targetSites,
    options: {
      generate_slug: request.options.generateSlug,
      overwrite: request.options.overwrite,
    },
  }

  // v5 — use Statamic.$axios (has baseURL + interceptors already set up)
  if (Statamic.$axios) {
    const response = await Statamic.$axios.post('/cp/content-translator/translate', payload)
    return response.data as TriggerResponse
  }

  // v6 — fall back to native fetch with XSRF-TOKEN
  const response = await fetch('/cp/content-translator/translate', {
    method: 'POST',
    headers: jsonHeaders(),
    body: JSON.stringify(payload),
  })

  if (!response.ok) {
    try {
      const body = await response.json()
      throw normalizeApiError(body)
    } catch (error) {
      if (error && typeof error === 'object' && 'code' in error) throw error

      throw {
        code: 'unexpected_error',
        message: `HTTP ${response.status}: ${response.statusText}`,
        retryable: false,
      } satisfies NormalizedError
    }
  }

  return (await response.json()) as TriggerResponse
}

/**
 * Poll the status of a set of job IDs.
 */
export async function checkStatus(jobIds: string[]): Promise<StatusResponse> {
  const params = jobIds.map((id) => `jobs[]=${encodeURIComponent(id)}`).join('&')
  const url = `/cp/content-translator/status?${params}`

  // v5 — use Statamic.$axios
  if (Statamic.$axios) {
    const response = await Statamic.$axios.get('/cp/content-translator/status', {
      params: { jobs: jobIds },
    })
    return response.data as StatusResponse
  }

  // v6 — fall back to native fetch
  const response = await fetch(url, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': getXsrfToken(),
    },
  })

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`)
  }

  return (await response.json()) as StatusResponse
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
