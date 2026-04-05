/**
 * Job status polling with adaptive backoff.
 *
 * Polls the status endpoint at 1-second intervals, increasing to 2-second
 * intervals after 10 attempts (capped at 3 seconds). Stops automatically
 * once all jobs reach a terminal state or `maxAttempts` is exceeded.
 */
import { checkStatus, mapApiJob } from './api'
import type { TranslationJob } from './types'

export interface PollOptions {
  /** Initial polling interval in milliseconds (default: 1000). */
  interval?: number
  /** Maximum number of polling attempts before giving up (default: 60). */
  maxAttempts?: number
  /** Called on every successful poll with the latest job statuses. */
  onStatus: (jobs: TranslationJob[]) => void
  /** Called once when polling gives up due to timeout/error threshold. */
  onTimeout?: () => void
}

/**
 * Begin polling for the given job IDs.
 *
 * Calls `onStatus` with the latest job list on each successful poll.
 * Returns a stop function — call it to cancel polling early (e.g., when
 * the dialog is closed).
 */
export function pollJobs(jobIds: string[], options: PollOptions): () => void {
  const { interval = 1000, maxAttempts = 60, onStatus, onTimeout } = options
  let attempts = 0
  let consecutiveErrors = 0
  let stopped = false
  let timeoutId: ReturnType<typeof setTimeout> | null = null
  let timedOut = false

  const handleTimeout = (): void => {
    if (timedOut || stopped) return

    timedOut = true
    onTimeout?.()
  }

  const poll = async (): Promise<void> => {
    if (stopped) return

    if (attempts >= maxAttempts) {
      handleTimeout()
      return
    }

    attempts++

    try {
      const result = await checkStatus(jobIds)
      if (stopped) return
      consecutiveErrors = 0

      const jobs = result.jobs.map(mapApiJob)
      onStatus(jobs)

      const allTerminal = jobs.every((j) => j.status === 'completed' || j.status === 'failed')

      if (allTerminal) return
    } catch (err) {
      consecutiveErrors++
      console.error('[magic-translator] polling error:', err)

      if (consecutiveErrors >= 10) {
        handleTimeout()
        return
      }
    }

    // Adaptive backoff: double the interval after the first 10 fast attempts
    const delay = attempts > 10 ? Math.min(interval * 2, 3000) : interval
    timeoutId = setTimeout(() => void poll(), delay)
  }

  void poll()

  return (): void => {
    stopped = true
    if (timeoutId !== null) {
      clearTimeout(timeoutId)
      timeoutId = null
    }
  }
}
