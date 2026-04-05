import { checkStatus, mapApiJob, triggerTranslation } from './api'
import { normalizeApiError } from './errors'
import { pollJobs } from './polling'
import type { LocaleJobState, TranslationJob } from './types'

declare const Statamic: {
  $toast: {
    success: (msg: string) => void
    error: (msg: string) => void
  }
}

declare function __(key: string, replacements?: Record<string, string | number>): string

interface TranslationOptions {
  generateSlug: boolean
  overwrite: boolean
}

export interface TranslationSession {
  key: string
  entryIds: string[]
  sourceSite: string
  selectedLocales: string[]
  options: TranslationOptions
  localeState: Record<string, LocaleJobState>
  isTranslating: boolean
  isComplete: boolean
  hasFailed: boolean
  startedAt: number
}

interface InternalSession extends TranslationSession {
  stopPolling: (() => void) | null
  toastFired: boolean
}

export type SessionListener = (session: TranslationSession) => void

const sessions = new Map<string, InternalSession>()
const listeners = new Map<string, Set<SessionListener>>()

export function sessionKey(entryIds: string[]): string {
  if (entryIds.length <= 1) {
    return `entry:${entryIds[0] ?? ''}`
  }

  const sorted = [...entryIds].sort()
  return `bulk:${sorted.join(',')}`
}

export function getSession(key: string): TranslationSession | null {
  const session = sessions.get(key)
  return session ? cloneSession(session) : null
}

export function subscribe(key: string, listener: SessionListener): () => void {
  const keyListeners = listeners.get(key) ?? new Set<SessionListener>()
  keyListeners.add(listener)
  listeners.set(key, keyListeners)

  const existing = sessions.get(key)
  if (existing) {
    listener(cloneSession(existing))
  }

  return (): void => {
    const current = listeners.get(key)
    if (!current) return

    current.delete(listener)
    if (current.size === 0) {
      listeners.delete(key)
    }
  }
}

export async function startTranslation(config: {
  entryIds: string[]
  sourceSite: string
  selectedLocales: string[]
  options: TranslationOptions
}): Promise<string> {
  const key = sessionKey(config.entryIds)
  const existing = sessions.get(key)

  if (existing && !existing.isComplete) {
    throw new Error(`[magic-translator] Session already running for key: ${key}`)
  }

  if (existing) {
    existing.stopPolling?.()
    sessions.delete(key)
  }

  const totalEntries = config.entryIds.length
  const localeState: Record<string, LocaleJobState> = {}

  for (const handle of config.selectedLocales) {
    localeState[handle] = {
      status: 'pending',
      error: null,
      errorCode: null,
      completedCount: 0,
      totalCount: totalEntries,
      jobIds: [],
    }
  }

  const session: InternalSession = {
    key,
    entryIds: [...config.entryIds],
    sourceSite: config.sourceSite,
    selectedLocales: [...config.selectedLocales],
    options: {
      generateSlug: config.options.generateSlug,
      overwrite: config.options.overwrite,
    },
    localeState,
    isTranslating: config.selectedLocales.length > 0,
    isComplete: false,
    hasFailed: false,
    startedAt: Date.now(),
    stopPolling: null,
    toastFired: false,
  }

  sessions.set(key, session)
  notify(key)

  const allJobIds: string[] = []

  try {
    for (const entryId of session.entryIds) {
      const result = await triggerTranslation({
        entryId,
        sourceSite: session.sourceSite,
        targetSites: session.selectedLocales,
        options: session.options,
      })

      if (!result.success) {
        const normalized = normalizeApiError(result.error ?? t('error_trigger_failed'))

        for (const handle of session.selectedLocales) {
          const state = session.localeState[handle]
          if (!state) continue

          session.localeState[handle] = {
            ...state,
            status: 'failed',
            error: normalized.message,
            errorCode: normalized.code,
            completedCount: Math.min(state.completedCount + 1, state.totalCount),
          }
        }

        continue
      }

      for (const job of result.jobs) {
        allJobIds.push(job.id)

        const state = session.localeState[job.target_site]
        if (!state) continue

        session.localeState[job.target_site] = {
          ...state,
          jobIds: [...state.jobIds, job.id],
          status: 'pending',
        }
      }
    }
  } catch (error) {
    const normalized = normalizeApiError(error)

    for (const handle of session.selectedLocales) {
      const state = session.localeState[handle]
      if (!state) continue

      session.localeState[handle] = {
        ...state,
        status: 'failed',
        error: normalized.message,
        errorCode: normalized.code,
      }
    }

    updateSessionFlags(session)
    notify(key)
    finalizeIfComplete(session)

    return key
  }

  updateSessionFlags(session)
  notify(key)

  if (allJobIds.length === 0) {
    finalizeIfComplete(session)
    return key
  }

  startPolling(session, allJobIds)

  return key
}

export async function retryLocale(key: string, handle: string): Promise<void> {
  const session = sessions.get(key)
  if (!session) return

  const state = session.localeState[handle]
  if (!state) return

  session.localeState[handle] = {
    ...state,
    status: 'pending',
    error: null,
    errorCode: null,
    completedCount: 0,
    jobIds: [],
  }

  updateSessionFlags(session)
  notify(key)

  const newJobIds: string[] = []
  let lastError: ReturnType<typeof normalizeApiError> | null = null

  for (const entryId of session.entryIds) {
    try {
      const result = await triggerTranslation({
        entryId,
        sourceSite: session.sourceSite,
        targetSites: [handle],
        options: {
          generateSlug: session.options.generateSlug,
          overwrite: true,
        },
      })

      if (result.success) {
        for (const job of result.jobs) {
          newJobIds.push(job.id)
        }
      } else {
        lastError = normalizeApiError(result.error ?? t('error_trigger_failed'))
      }
    } catch (error) {
      lastError = normalizeApiError(error)
    }
  }

  const latest = sessions.get(key)
  if (!latest) return

  if (newJobIds.length === 0) {
    const locale = latest.localeState[handle]
    if (locale) {
      latest.localeState[handle] = {
        ...locale,
        status: 'failed',
        error: lastError?.message ?? t('translation_failed'),
        errorCode: lastError?.code ?? 'unexpected_error',
      }
    }

    updateSessionFlags(latest)
    notify(key)
    finalizeIfComplete(latest)

    return
  }

  const locale = latest.localeState[handle]
  if (locale) {
    latest.localeState[handle] = {
      ...locale,
      jobIds: newJobIds,
    }
  }

  const existingIds = Object.values(latest.localeState).flatMap((entry) => entry.jobIds)
  const mergedIds = [...new Set([...existingIds, ...newJobIds])]

  startPolling(latest, mergedIds)
  updateSessionFlags(latest)
  notify(key)
}

function startPolling(session: InternalSession, jobIds: string[]): void {
  session.stopPolling?.()

  const ids = [...new Set(jobIds)]
  if (ids.length === 0) {
    updateSessionFlags(session)
    notify(session.key)
    finalizeIfComplete(session)
    return
  }

  session.isTranslating = true

  session.stopPolling = pollJobs(
    ids,
    (jobs) => {
      const latest = sessions.get(session.key)
      if (!latest) return

      applyJobSnapshot(latest, jobs)
      updateSessionFlags(latest)
      notify(latest.key)
      finalizeIfComplete(latest)
    },
    { maxAttempts: 600 },
  )
}

function applyJobSnapshot(session: InternalSession, jobs: TranslationJob[]): void {
  for (const [handle, state] of Object.entries(session.localeState)) {
    const relatedJobs = jobs.filter((job) => state.jobIds.includes(job.id))
    if (relatedJobs.length === 0) continue

    const completedCount = relatedJobs.filter((job) => job.status === 'completed').length
    const failedJobs = relatedJobs.filter((job) => job.status === 'failed')
    const terminalCount = completedCount + failedJobs.length
    const hasRunning = relatedJobs.some((job) => job.status === 'running')
    const hasPending = relatedJobs.some((job) => job.status === 'pending')
    const normalizedFailedJob = failedJobs[0] ? normalizeApiError(failedJobs[0].error) : null

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

    session.localeState[handle] = {
      ...state,
      status: nextStatus,
      error: normalizedFailedJob?.message ?? null,
      errorCode: normalizedFailedJob?.code ?? null,
      completedCount: terminalCount,
    }
  }
}

function updateSessionFlags(session: InternalSession): void {
  session.hasFailed = Object.values(session.localeState).some((state) => state.status === 'failed')

  session.isComplete =
    session.selectedLocales.length > 0 &&
    session.selectedLocales.every((handle) => {
      const state = session.localeState[handle]
      if (!state) return false

      return state.status === 'completed' || state.status === 'failed'
    })

  session.isTranslating = !session.isComplete && session.selectedLocales.length > 0
}

function finalizeIfComplete(session: InternalSession): void {
  if (!session.isComplete) return

  session.stopPolling?.()
  session.stopPolling = null

  if (session.toastFired) return

  const statuses = session.selectedLocales
    .map((handle) => session.localeState[handle])
    .filter((state): state is LocaleJobState => Boolean(state))

  const failed = statuses.filter((state) => state.status === 'failed').length
  const succeeded = statuses.filter((state) => state.status === 'completed').length

  if (failed === 0) {
    Statamic.$toast.success(t('toast_translation_complete'))
  } else if (succeeded === 0) {
    Statamic.$toast.error(t('toast_translation_failed'))
  } else {
    Statamic.$toast.error(t('toast_translation_partial', { failed }))
  }

  session.toastFired = true
}

function notify(key: string): void {
  const session = sessions.get(key)
  if (!session) return

  persistSessions()

  const keyListeners = listeners.get(key)
  if (!keyListeners || keyListeners.size === 0) return

  const snapshot = cloneSession(session)
  for (const listener of keyListeners) {
    listener(snapshot)
  }
}

function cloneSession(session: TranslationSession): TranslationSession {
  const localeState: Record<string, LocaleJobState> = {}

  for (const [handle, state] of Object.entries(session.localeState)) {
    localeState[handle] = {
      ...state,
      jobIds: [...state.jobIds],
    }
  }

  return {
    key: session.key,
    entryIds: [...session.entryIds],
    sourceSite: session.sourceSite,
    selectedLocales: [...session.selectedLocales],
    options: {
      generateSlug: session.options.generateSlug,
      overwrite: session.options.overwrite,
    },
    localeState,
    isTranslating: session.isTranslating,
    isComplete: session.isComplete,
    hasFailed: session.hasFailed,
    startedAt: session.startedAt,
  }
}

function t(key: string, replacements: Record<string, string | number> = {}): string {
  return __('magic-translator::messages.' + key, replacements)
}

// ─────────────────────────────────────────────────────────────────────────────
// Cross-reload persistence
//
// Non-terminal sessions are mirrored to localStorage so that a full page
// reload can reconstruct them. On module load we re-query the server for
// fresh job statuses (server is source of truth) and resume polling any
// sessions still in flight. Stale records (> 24h) and sessions whose jobs
// have been purged server-side are dropped silently.
// ─────────────────────────────────────────────────────────────────────────────

const STORAGE_KEY = 'magic-translator:sessions'
const MAX_AGE_MS = 24 * 60 * 60 * 1000

interface PersistedSession {
  key: string
  entryIds: string[]
  sourceSite: string
  selectedLocales: string[]
  options: TranslationOptions
  startedAt: number
  jobIdsByHandle: Record<string, string[]>
  totalCountByHandle: Record<string, number>
}

function persistSessions(): void {
  if (typeof localStorage === 'undefined') return

  const records: PersistedSession[] = []
  for (const session of sessions.values()) {
    if (session.isComplete) continue

    const jobIdsByHandle: Record<string, string[]> = {}
    const totalCountByHandle: Record<string, number> = {}
    for (const [handle, state] of Object.entries(session.localeState)) {
      jobIdsByHandle[handle] = [...state.jobIds]
      totalCountByHandle[handle] = state.totalCount
    }

    records.push({
      key: session.key,
      entryIds: [...session.entryIds],
      sourceSite: session.sourceSite,
      selectedLocales: [...session.selectedLocales],
      options: { ...session.options },
      startedAt: session.startedAt,
      jobIdsByHandle,
      totalCountByHandle,
    })
  }

  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(records))
  } catch {
    // localStorage full or unavailable — non-fatal
  }
}

function dropPersisted(key: string): void {
  if (typeof localStorage === 'undefined') return

  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return
    const filtered = (parsed as PersistedSession[]).filter((r) => r.key !== key)
    localStorage.setItem(STORAGE_KEY, JSON.stringify(filtered))
  } catch {
    // ignore
  }
}

async function rehydrateSessions(): Promise<void> {
  if (typeof localStorage === 'undefined') return

  let records: PersistedSession[]
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return
    records = parsed as PersistedSession[]
  } catch {
    return
  }

  const now = Date.now()
  const fresh = records.filter((r) => typeof r.startedAt === 'number' && now - r.startedAt < MAX_AGE_MS)

  if (fresh.length !== records.length) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(fresh))
    } catch {
      // ignore
    }
  }

  await Promise.all(
    fresh.map((record) =>
      rehydrateSession(record).catch((err) => {
        console.error('[magic-translator] session rehydration error:', err)
      }),
    ),
  )
}

async function rehydrateSession(record: PersistedSession): Promise<void> {
  if (sessions.has(record.key)) return

  const allJobIds = Object.values(record.jobIdsByHandle).flat()
  if (allJobIds.length === 0) return

  const statusResponse = await checkStatus(allJobIds)
  const jobs = statusResponse.jobs.map(mapApiJob)
  const jobsById = new Map(jobs.map((j) => [j.id, j]))

  const localeState: Record<string, LocaleJobState> = {}

  for (const handle of record.selectedLocales) {
    const jobIds = record.jobIdsByHandle[handle] ?? []
    const knownJobs = jobIds.map((id) => jobsById.get(id)).filter((j): j is TranslationJob => Boolean(j))

    if (knownJobs.length === 0) continue

    const completedCount = knownJobs.filter((j) => j.status === 'completed').length
    const failedJobs = knownJobs.filter((j) => j.status === 'failed')
    const terminalCount = completedCount + failedJobs.length
    const hasRunning = knownJobs.some((j) => j.status === 'running')
    const hasPending = knownJobs.some((j) => j.status === 'pending')
    const normalizedFailedJob = failedJobs[0] ? normalizeApiError(failedJobs[0].error) : null

    let status: LocaleJobState['status'] = 'pending'
    if (failedJobs.length > 0) status = 'failed'
    else if (terminalCount === knownJobs.length) status = 'completed'
    else if (hasRunning) status = 'running'
    else if (hasPending) status = 'pending'

    localeState[handle] = {
      status,
      error: normalizedFailedJob?.message ?? null,
      errorCode: normalizedFailedJob?.code ?? null,
      completedCount: terminalCount,
      totalCount: record.totalCountByHandle[handle] ?? knownJobs.length,
      jobIds: [...jobIds],
    }
  }

  if (Object.keys(localeState).length === 0) {
    // All jobs expired / purged server-side — drop silently.
    dropPersisted(record.key)
    return
  }

  if (sessions.has(record.key)) return

  const session: InternalSession = {
    key: record.key,
    entryIds: [...record.entryIds],
    sourceSite: record.sourceSite,
    selectedLocales: [...record.selectedLocales],
    options: { ...record.options },
    localeState,
    isTranslating: false,
    isComplete: false,
    hasFailed: false,
    startedAt: record.startedAt,
    stopPolling: null,
    toastFired: false,
  }

  sessions.set(record.key, session)
  updateSessionFlags(session)

  if (session.isComplete) {
    // Completed while we were gone — no toast, just silent final-state display.
    session.toastFired = true
    dropPersisted(record.key)
    notify(record.key)
    return
  }

  const remainingIds = Object.values(session.localeState).flatMap((s) => s.jobIds)
  notify(record.key)
  startPolling(session, remainingIds)
}

// Fire-and-forget rehydration at module load.
void rehydrateSessions()
