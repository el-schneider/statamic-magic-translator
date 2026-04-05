/**
 * Magic Translator — Statamic v6 entry point (Vue 3 / Composition API).
 *
 * Registers the fieldtype component and the translation dialog, then wires
 * up the bulk-action callback so the "Translate" entry action can open the
 * shared dialog with a set of entry IDs.
 */
import type { SiteDescriptor } from '../core/types'
import TranslationDialog from './components/TranslationDialog.vue'
import TranslatorFieldtype from './components/TranslatorFieldtype.vue'

Statamic.booting?.(() => {
  // ── Component registration ─────────────────────────────────────────────

  // The fieldtype component is auto-injected into configured collection
  // blueprints by the PHP ServiceProvider (EntryBlueprintFound listener).
  Statamic.$components?.register('magic_translator-fieldtype', TranslatorFieldtype)

  // The dialog is opened programmatically via $components.append.
  Statamic.$components?.register('magic-translator-dialog', TranslationDialog)

  // ── Bulk action callback ───────────────────────────────────────────────

  /**
   * Called by the TranslateEntryAction PHP bulk action when the user selects
   * entries and clicks "Translate".
   *
   * @param entryIds  UUIDs of the selected entries.
   * @param sites     Site list passed from PHP (handle + name pairs).
   */
  Statamic.$callbacks?.add('openTranslationDialog', (entryIds: unknown, sites: unknown) => {
    const ids = Array.isArray(entryIds) ? (entryIds as string[]) : []
    const siteList = Array.isArray(sites) ? (sites as SiteDescriptor[]) : []

    if (ids.length === 0 || !Statamic.$components) return

    const dialog = Statamic.$components.append('magic-translator-dialog', {
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
