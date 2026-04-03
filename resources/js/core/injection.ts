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
 * Important: panel detection intentionally does NOT rely on translated
 * heading text like "Sites". Instead, we score structural candidates by
 * how well their locale-row names match the known site names/handles.
 */
import type { SiteMeta } from './types'

declare function __(key: string, replacements?: Record<string, string | number>): string

/** Data attribute stamped on every injected badge element. */
const BADGE_ATTR = 'data-ct-badge'

/** Data attribute stamped on every injected translate button element. */
const BUTTON_ATTR = 'data-ct-translate-button'

/** localStorage key used to remember a successful injection. */
const STORAGE_KEY = 'ct-badge-injection-succeeded'

/** localStorage key used to remember a successful button injection. */
const BUTTON_STORAGE_KEY = 'ct-translate-button-injection-succeeded'

const I18N_DEFAULTS: Record<string, string> = {
  translate_button: 'Translate',
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

// ── Sites panel finders ──────────────────────────────────────────────────────

function normalizeSiteToken(value: string): string {
  return value.trim().toLocaleLowerCase()
}

function getSiteTokens(site: SiteMeta): Set<string> {
  const tokens = new Set<string>()

  for (const value of [site.name, site.handle]) {
    if (!value) continue

    tokens.add(normalizeSiteToken(value))

    // Site names can be translation keys in some setups.
    try {
      tokens.add(normalizeSiteToken(__(value)))
    } catch {
      // ignore
    }
  }

  return tokens
}

function buildSiteTokenSet(localeStatuses: SiteMeta[]): Set<string> {
  const tokens = new Set<string>()

  for (const site of localeStatuses) {
    for (const token of getSiteTokens(site)) {
      tokens.add(token)
    }
  }

  return tokens
}

function findSiteStatusForRowName(rowName: string, localeStatuses: SiteMeta[]): SiteMeta | null {
  const rowToken = normalizeSiteToken(rowName)

  for (const site of localeStatuses) {
    if (getSiteTokens(site).has(rowToken)) {
      return site
    }
  }

  return null
}

function scorePanelCandidate(panel: Element, version: 'v5' | 'v6', localeStatuses: SiteMeta[]): number {
  const rows = findLocaleRows(panel, version)
  if (rows.length === 0) return -1

  const tokens = buildSiteTokenSet(localeStatuses)
  const matched = new Set<string>()

  for (const row of rows) {
    const rowName = getSiteNameFromRow(row)
    if (!rowName) continue

    const token = normalizeSiteToken(rowName)
    if (tokens.has(token)) {
      matched.add(token)
    }
  }

  const matchCount = matched.size
  if (matchCount === 0) return -1

  const expected = localeStatuses.length
  const countPenalty = Math.abs(rows.length - expected)
  return matchCount * 100 - countPenalty
}

function findBestPanelCandidate(
  candidates: Element[],
  version: 'v5' | 'v6',
  localeStatuses: SiteMeta[],
): Element | null {
  let best: Element | null = null
  let bestScore = -1

  for (const candidate of candidates) {
    const score = scorePanelCandidate(candidate, version, localeStatuses)
    if (score > bestScore) {
      best = candidate
      bestScore = score
    }
  }

  return best
}

/**
 * v5: gather structural panel candidates around rows that look like locale
 * rows and choose the best match by site-name/handle similarity.
 */
function findSitesPanelV5(localeStatuses: SiteMeta[]): Element | null {
  const rows = Array.from(document.querySelectorAll('div.text-sm')).filter((el) => el.querySelector('.little-dot'))
  if (rows.length === 0) return null

  const candidates = new Set<Element>()

  for (const row of rows) {
    let cursor: Element | null = row.parentElement
    let depth = 0

    while (cursor && depth < 4) {
      candidates.add(cursor)
      cursor = cursor.parentElement
      depth++
    }
  }

  return findBestPanelCandidate(Array.from(candidates), 'v5', localeStatuses)
}

/**
 * v6: evaluate all `[data-ui-panel]` elements and choose the one whose locale
 * rows best match known site names/handles.
 */
function findSitesPanelV6(localeStatuses: SiteMeta[]): Element | null {
  const candidates = Array.from(document.querySelectorAll('[data-ui-panel]'))
  return findBestPanelCandidate(candidates, 'v6', localeStatuses)
}

function findSitesPanel(localeStatuses: SiteMeta[], version: 'v5' | 'v6'): Element | null {
  return version === 'v5' ? findSitesPanelV5(localeStatuses) : findSitesPanelV6(localeStatuses)
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

type TranslateButtonOptions = {
  onClick: () => void
  disabled?: boolean
}

function createTranslateButton(version: 'v5' | 'v6', options: TranslateButtonOptions): HTMLButtonElement {
  const button = document.createElement('button')
  button.setAttribute(BUTTON_ATTR, '1')
  button.setAttribute('type', 'button')
  button.textContent = t('translate_button')

  if (version === 'v6') {
    // Match Statamic v6 UI Button styles (`variant=default`, `size=sm`).
    button.setAttribute(
      'class',
      [
        'mt-2 w-full',
        'relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline',
        'disabled:text-gray-400 dark:disabled:text-gray-600 disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed',
        '[&_svg]:shrink-0 [&_svg]:text-gray-925 [&_svg]:opacity-60 dark:[&_svg]:text-white',
        'bg-linear-to-b from-white to-gray-50 hover:to-gray-100 hover:bg-gray-50 text-gray-900 border border-gray-300 with-contrast:border-gray-500 shadow-ui-sm',
        'dark:from-gray-850 dark:to-gray-900 dark:hover:to-gray-850 dark:hover:bg-gray-900 dark:border-gray-700/80 dark:text-gray-300 dark:shadow-ui-md',
        'px-3 h-8 text-[0.8125rem] leading-tight gap-2 rounded-lg [&_svg]:size-3',
      ].join(' '),
    )
    button.setAttribute('data-ui-group-target', 'true')
  } else {
    button.setAttribute('class', 'btn btn-sm w-full mt-2')
  }

  if (options.disabled) {
    button.disabled = true
    button.setAttribute('aria-disabled', 'true')
  }

  button.addEventListener('click', () => {
    if (!button.disabled) {
      options.onClick()
    }
  })

  return button
}

function findTranslateButtonContainer(panel: Element, version: 'v5' | 'v6'): Element {
  const rows = findLocaleRows(panel, version)
  const firstRow = rows[0]

  if (firstRow?.parentElement) {
    return firstRow.parentElement
  }

  if (version === 'v6') {
    return panel.querySelector('[data-ui-card]') ?? panel
  }

  return panel
}

// ── Public API ───────────────────────────────────────────────────────────────

/**
 * Inject status badges into the native Sites panel.
 *
 * Returns `true` if injection succeeded (panel found and rows matched),
 * `false` if the panel was not found in the current DOM.
 */
export function injectBadges(localeStatuses: SiteMeta[], version: 'v5' | 'v6'): boolean {
  const panel = findSitesPanel(localeStatuses, version)
  if (!panel) return false

  // Remove any stale badges before re-injecting
  removeBadges()

  const rows = findLocaleRows(panel, version)
  if (rows.length === 0) return false

  let injectedCount = 0

  for (const row of rows) {
    const siteName = getSiteNameFromRow(row)
    if (!siteName) continue

    // Match the row to a locale status by name/handle (translation-safe).
    const siteStatus = findSiteStatusForRowName(siteName, localeStatuses)
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
 * Inject a "Translate" button directly into the native Sites panel.
 */
export function injectTranslateButton(
  localeStatuses: SiteMeta[],
  version: 'v5' | 'v6',
  options: TranslateButtonOptions,
): boolean {
  const panel = findSitesPanel(localeStatuses, version)
  if (!panel) return false

  removeTranslateButtons()

  const container = findTranslateButtonContainer(panel, version)
  const button = createTranslateButton(version, options)
  container.appendChild(button)

  try {
    localStorage.setItem(BUTTON_STORAGE_KEY, '1')
  } catch {
    // localStorage might be unavailable
  }

  return true
}

/**
 * Remove all previously injected translate buttons from the DOM.
 */
export function removeTranslateButtons(): void {
  document.querySelectorAll(`[${BUTTON_ATTR}]`).forEach((el) => el.remove())
}

/**
 * Returns true if translate-button injection previously succeeded in this session.
 */
export function wasTranslateButtonPreviouslyInjected(): boolean {
  try {
    return localStorage.getItem(BUTTON_STORAGE_KEY) === '1'
  } catch {
    return false
  }
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
