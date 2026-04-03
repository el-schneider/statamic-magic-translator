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
}

/**
 * Begin polling for the given job IDs.
 *
 * Calls `onUpdate` with the latest job list on each successful poll.
 * Returns a stop function — call it to cancel polling early (e.g., when
 * the dialog is closed).
 */
export function pollJobs(
  jobIds: string[],
  onUpdate: (jobs: TranslationJob[]) => void,
  options: PollOptions = {},
): () => void {
  const { interval = 1000, maxAttempts = 60 } = options
  let attempts = 0
  let stopped = false
  let timeoutId: ReturnType<typeof setTimeout> | null = null

  const poll = async (): Promise<void> => {
    if (stopped) return
    attempts++

    try {
      const result = await checkStatus(jobIds)
      if (stopped) return

      const jobs = result.jobs.map(mapApiJob)
      onUpdate(jobs)

      const allTerminal = jobs.every((j) => j.status === 'completed' || j.status === 'failed')

      if (allTerminal || attempts >= maxAttempts) return
    } catch (err) {
      console.error('[content-translator] polling error:', err)
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
