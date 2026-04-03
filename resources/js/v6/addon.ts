/**
 * Content Translator — Statamic v6 entry point (Vue 3 / Composition API).
 *
 * Registers the fieldtype component and the translation dialog, then wires
 * up the bulk-action callback so the "Translate" entry action can open the
 * shared dialog with a set of entry IDs.
 */
import type { Axios } from 'axios'
import type { SiteDescriptor } from '../core/types'
import TranslationDialog from './components/TranslationDialog.vue'
import TranslatorFieldtype from './components/TranslatorFieldtype.vue'

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

  function __(key: string, replacements?: Record<string, string | number>): string
}

Statamic.booting(() => {
  // ── Component registration ─────────────────────────────────────────────

  // The fieldtype component is auto-injected into configured collection
  // blueprints by the PHP ServiceProvider (EntryBlueprintFound listener).
  Statamic.$components.register('content_translator-fieldtype', TranslatorFieldtype)

  // The dialog is opened programmatically via $components.append.
  Statamic.$components.register('content-translator-dialog', TranslationDialog)

  // ── Bulk action callback ───────────────────────────────────────────────

  /**
   * Called by the TranslateEntryAction PHP bulk action when the user selects
   * entries and clicks "Translate".
   *
   * @param entryIds  UUIDs of the selected entries.
   * @param sites     Site list passed from PHP (handle + name pairs).
   */
  Statamic.$callbacks.add('openTranslationDialog', (entryIds: unknown, sites: unknown) => {
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
  })
})
