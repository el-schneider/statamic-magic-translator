/**
 * Badge injection into the Statamic CP Sites panel.
 *
 * Attempts to find the native Sites panel rendered by Statamic in the
 * entry publish form sidebar and inject small translation-status badges
 * (timestamp, "outdated" warning, "—" for missing) next to each locale row.
 *
 * Falls back gracefully if the DOM can't be located — the fieldtype
 * component renders its own standalone status list in that case.
 *
 * Version-aware selectors:
 *  - v5: `label.publish-field-label` with text "Sites" → sibling `div` rows
 *  - v6: `[data-ui-heading]` with text "Sites" → ancestor `[data-ui-panel]` → `button` rows
 */
import type { SiteMeta } from './types'

declare function __(key: string, replacements?: Record<string, string | number>): string

/** Data attribute stamped on every injected badge element. */
const BADGE_ATTR = 'data-ct-badge'

/** localStorage key used to remember a successful injection. */
const STORAGE_KEY = 'ct-badge-injection-succeeded'

const I18N_DEFAULTS: Record<string, string> = {
  sites_panel_label: 'Sites',
  badge_outdated: 'outdated',
  badge_manual: 'manual',
  time_just_now: 'just now',
  time_minutes_ago: ':countm ago',
  time_hours_ago: ':counth ago',
  time_days_ago: ':countd ago',
  last_translated: 'Last translated: :time',
}

function t(key: string, replacements: Record<string, string | number> = {}): string {
  const fullKey = `content-translator::messages.${key}`
  let text = I18N_DEFAULTS[key] ?? key

  try {
    text = __(fullKey, replacements)
  } catch {
    // ignore
  }

  for (const [name, value] of Object.entries(replacements)) {
    text = text.replaceAll(`:${name}`, String(value))
  }

  return text
}

// ── DOM helpers ─────────────────────────────────────────────────────────────

/** Trimmed text content of an element. */
function textOf(el: Element): string {
  return (el.textContent ?? '').trim()
}

/**
 * Traverse up from `el` looking for an ancestor that matches `attr`.
 */
function closestByAttr(el: Element | null, attr: string): Element | null {
  let cursor = el
  while (cursor) {
    if (cursor.hasAttribute(attr)) return cursor
    cursor = cursor.parentElement
  }
  return null
}

// ── Sites panel finders ──────────────────────────────────────────────────────

/**
 * v5: look for `<label class="publish-field-label …">Sites</label>` and
 * return the enclosing card/container element that also holds the locale rows.
 */
function findSitesPanelV5(): Element | null {
  const labels = document.querySelectorAll('label.publish-field-label')
  const sitesLabel = t('sites_panel_label')

  for (const label of labels) {
    const text = textOf(label)
    if (text === sitesLabel) {
      // The parent div contains both the label and the locale row divs
      return label.parentElement
    }
  }
  return null
}

/**
 * v6: look for `[data-ui-heading]` whose text is "Sites" and return the
 * enclosing `[data-ui-panel]` element.
 */
function findSitesPanelV6(): Element | null {
  const headings = document.querySelectorAll('[data-ui-heading]')
  const sitesLabel = t('sites_panel_label')

  for (const heading of headings) {
    if (textOf(heading) === sitesLabel) {
      return closestByAttr(heading, 'data-ui-panel')
    }
  }
  return null
}

// ── Row finders ──────────────────────────────────────────────────────────────

/**
 * Return locale row elements within the detected Sites panel.
 *
 * v5: each row is a `div` that directly contains a `.little-dot` span.
 * v6: each row is a `button` element.
 */
function findLocaleRows(panel: Element, version: 'v5' | 'v6'): Element[] {
  if (version === 'v5') {
    // In v5, locale rows are divs with class "text-sm flex items-center"
    // that contain a .little-dot child
    return Array.from(panel.querySelectorAll('div.text-sm')).filter((el) => el.querySelector('.little-dot'))
  } else {
    // In v6, rows are <button> elements that contain .little-dot
    return Array.from(panel.querySelectorAll('button')).filter((el) => el.querySelector('.little-dot'))
  }
}

/**
 * Extract the site display name from a locale row element.
 *
 * The name is the text node immediately following the `.little-dot` span,
 * or the trimmed text of the `.flex-1` container as a fallback.
 */
function getSiteNameFromRow(row: Element): string {
  const dot = row.querySelector('.little-dot')
  if (dot) {
    // Walk siblings after the dot looking for a text node
    let node: ChildNode | null = dot.nextSibling
    while (node) {
      if (node.nodeType === Node.TEXT_NODE) {
        const text = (node.textContent ?? '').trim()
        if (text) return text
      }
      node = node.nextSibling
    }
  }

  // Fallback: first non-empty line from .flex-1 or the row itself
  const flex1 = row.querySelector('.flex-1')
  const source = flex1 ?? row
  return (
    (source.textContent ?? '')
      .split('\n')
      .map((s) => s.trim())
      .filter(Boolean)[0] ?? ''
  )
}

// ── Badge creation ───────────────────────────────────────────────────────────

/** Format an ISO timestamp as a human-readable relative time string. */
function relativeTime(iso: string): string {
  try {
    const diffMs = Date.now() - new Date(iso).getTime()
    const mins = Math.floor(diffMs / 60_000)
    if (mins < 1) return t('time_just_now')
    if (mins < 60) return t('time_minutes_ago', { count: mins })
    const hours = Math.floor(mins / 60)
    if (hours < 24) return t('time_hours_ago', { count: hours })
    return t('time_days_ago', { count: Math.floor(hours / 24) })
  } catch {
    return ''
  }
}

/** Build a small inline badge <span> for a given site status. */
function createBadge(site: SiteMeta): HTMLElement {
  const span = document.createElement('span')
  span.setAttribute(BADGE_ATTR, site.handle)

  if (!site.exists) {
    span.textContent = '—'
    span.setAttribute('style', 'font-size:11px;color:#9ca3af;margin-left:6px;')
  } else if (site.is_stale) {
    span.textContent = `⚠ ${t('badge_outdated')}`
    span.setAttribute('style', 'font-size:11px;color:#f59e0b;margin-left:6px;font-weight:500;')
    if (site.last_translated_at) {
      span.setAttribute('title', t('last_translated', { time: relativeTime(site.last_translated_at) }))
    }
  } else if (site.last_translated_at) {
    span.textContent = relativeTime(site.last_translated_at)
    span.setAttribute('title', t('last_translated', { time: site.last_translated_at }))
    span.setAttribute('style', 'font-size:11px;color:#6b7280;margin-left:6px;')
  } else {
    // Exists but no translation timestamp = manually created
    span.textContent = t('badge_manual')
    span.setAttribute('style', 'font-size:11px;color:#6b7280;margin-left:6px;font-style:italic;')
  }

  return span
}

// ── Public API ───────────────────────────────────────────────────────────────

/**
 * Inject status badges into the native Sites panel.
 *
 * Returns `true` if injection succeeded (panel found and rows matched),
 * `false` if the panel was not found in the current DOM.
 */
export function injectBadges(localeStatuses: SiteMeta[], version: 'v5' | 'v6'): boolean {
  const panel = version === 'v5' ? findSitesPanelV5() : findSitesPanelV6()
  if (!panel) return false

  // Remove any stale badges before re-injecting
  removeBadges()

  const rows = findLocaleRows(panel, version)
  if (rows.length === 0) return false

  let injectedCount = 0

  for (const row of rows) {
    const siteName = getSiteNameFromRow(row)
    if (!siteName) continue

    // Match the row to a locale status by display name or handle
    const siteStatus = localeStatuses.find((s) => s.name === siteName || s.handle === siteName)
    if (!siteStatus) continue

    const badge = createBadge(siteStatus)

    if (version === 'v6') {
      // In v6, inject into the badges flex container at the end of the row
      const badgesContainer =
        row.querySelector('[class~="gap-1.5"].flex.items-center') ?? row.querySelector('.flex.items-center:last-child')
      if (badgesContainer) {
        badgesContainer.insertBefore(badge, badgesContainer.firstChild)
      } else {
        row.appendChild(badge)
      }
    } else {
      // In v5, append directly to the row (after existing badges)
      row.appendChild(badge)
    }

    injectedCount++
  }

  if (injectedCount > 0) {
    try {
      localStorage.setItem(STORAGE_KEY, '1')
    } catch {
      // localStorage might be unavailable
    }
    return true
  }

  return false
}

/**
 * Remove all previously injected badges from the DOM.
 */
export function removeBadges(): void {
  document.querySelectorAll(`[${BADGE_ATTR}]`).forEach((el) => el.remove())
}

/**
 * Returns true if badge injection previously succeeded in this session.
 * Used to avoid a layout-shift flicker on re-mount.
 */
export function wasPreviouslyInjected(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}
