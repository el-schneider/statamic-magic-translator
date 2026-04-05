/**
 * Mark-current store.
 *
 * Optimistic UI overrides for locales that have been marked as current
 * during the current page session. The server-side meta is the source
 * of truth on subsequent page loads; this store only bridges the gap
 * between a successful API call and the next full preload.
 *
 * Scoped per entry so navigating between entries keeps overrides
 * isolated. Notifications are signal-only — listeners read current
 * state via `getMarkedHandles` on each callback.
 *
 * Design mirrors the translation session store (`store.ts`) so the two
 * share a single pub/sub pattern.
 */

type Listener = () => void

const handlesByEntry = new Map<string, Set<string>>()
const listenersByEntry = new Map<string, Set<Listener>>()

/**
 * Return the set of site handles marked as current for an entry in
 * this page session. The returned set is a fresh copy — safe to pass
 * into Vue refs without risking upstream mutation.
 */
export function getMarkedHandles(entryId: string): Set<string> {
  return new Set(handlesByEntry.get(entryId) ?? [])
}

/**
 * Check whether a specific site has been marked current for an entry.
 */
export function isMarkedCurrent(entryId: string, siteHandle: string): boolean {
  return handlesByEntry.get(entryId)?.has(siteHandle) ?? false
}

/**
 * Record that a site has been marked current for an entry and notify
 * subscribers. Idempotent.
 */
export function markSiteCurrent(entryId: string, siteHandle: string): void {
  const existing = handlesByEntry.get(entryId)

  if (existing?.has(siteHandle)) return

  const next = new Set(existing ?? [])
  next.add(siteHandle)
  handlesByEntry.set(entryId, next)

  notify(entryId)
}

/**
 * Subscribe to mark-current changes for a single entry. Returns an
 * unsubscribe function.
 */
export function subscribeMarked(entryId: string, listener: Listener): () => void {
  const set = listenersByEntry.get(entryId) ?? new Set<Listener>()
  set.add(listener)
  listenersByEntry.set(entryId, set)

  return (): void => {
    const current = listenersByEntry.get(entryId)
    if (!current) return

    current.delete(listener)
    if (current.size === 0) {
      listenersByEntry.delete(entryId)
    }
  }
}

/**
 * Clear all overrides for an entry. Exposed for completeness; not
 * currently called — overrides naturally reset on full page reload.
 */
export function clearMarked(entryId: string): void {
  if (!handlesByEntry.has(entryId)) return

  handlesByEntry.delete(entryId)
  notify(entryId)
}

function notify(entryId: string): void {
  const set = listenersByEntry.get(entryId)
  if (!set) return

  for (const listener of set) {
    listener()
  }
}
