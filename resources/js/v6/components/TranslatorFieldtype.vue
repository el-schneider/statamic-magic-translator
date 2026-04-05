<script setup lang="ts">
/**
 * TranslatorFieldtype — Vue 3 (Statamic v6)
 *
 * Auto-injected into the sidebar of entry publish forms for configured
 * collections. Renders a "Translate" button that opens the TranslationDialog.
 *
 * On mount it attempts to inject translation-status badges into the native
 * Sites panel (the locale switcher in the sidebar). If badge injection fails
 * (Sites panel not yet in DOM), a MutationObserver retries on every DOM
 * mutation. A fallback standalone status list is shown when injection has
 * never succeeded in the current session.
 */
import { Button } from '@statamic/cms/ui'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  injectBadges,
  injectTranslateButton,
  removeBadges,
  removeTranslateButtons,
  wasPreviouslyInjected,
  wasTranslateButtonPreviouslyInjected,
} from '../../core/injection'
import { getMarkedHandles, subscribeMarked } from '../../core/markCurrentStore'
import type { FieldtypePreload } from '../../core/types'

declare function __(key: string, replacements?: Record<string, string | number>): string

declare const Statamic: {
  $components: {
    append: (
      name: string,
      options: { props: Record<string, unknown> },
    ) => {
      on: (event: string, handler: () => void) => void
      destroy: () => void
    }
  }
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
  /** Field handle */
  handle: string
  /** Field value (unused, but required by Statamic fieldtype contract) */
  value: unknown
  /** Pre-loaded meta from PHP MagicTranslatorFieldtype::preload() */
  meta: FieldtypePreload
  /** Field config object */
  config: Record<string, unknown>
}>()

// ── Derived ──────────────────────────────────────────────────────────────────

const sites = computed(() => props.meta?.sites ?? [])
const currentSite = computed(() => props.meta?.current_site ?? null)
const originSite = computed(() => props.meta?.origin_site ?? null)
const entryId = computed(() => props.meta?.entry_id ?? null)

/** Sites that can be translation targets (all sites except the current one). */
const targetSites = computed(() => sites.value.filter((s) => s.handle !== currentSite.value))

const hasTargets = computed(() => targetSites.value.length > 0)

const markedCurrentHandles = ref<Set<string>>(entryId.value ? getMarkedHandles(entryId.value) : new Set())

const effectiveSites = computed(() =>
  sites.value.map((site) =>
    markedCurrentHandles.value.has(site.handle)
      ? { ...site, is_stale: false, last_translated_at: new Date().toISOString() }
      : site,
  ),
)

// ── Badge injection ───────────────────────────────────────────────────────────

/**
 * Whether the badge injection currently succeeds.
 * Stored as a ref so the template can conditionally render the fallback status.
 */
const badgeInjected = ref(wasPreviouslyInjected())
const buttonInjected = ref(wasTranslateButtonPreviouslyInjected())

let observer: MutationObserver | null = null
let injecting = false
const rootEl = ref<HTMLElement | null>(null)

function hideFieldLabelChrome(): void {
  const root = rootEl.value
  if (!root) return

  const wrappers = [root.closest('[data-ui-input-group]'), root.closest('.publish-field')].filter(
    (el): el is Element => el !== null,
  )

  for (const wrapper of wrappers) {
    wrapper.querySelectorAll('[data-ui-field-header], [data-ui-field-text], .publish-field-label').forEach((el) => {
      ;(el as HTMLElement).style.display = 'none'
    })
  }
}

function hideEntireField(): void {
  const root = rootEl.value
  if (!root) return

  const wrappers = [root.closest('[data-ui-input-group]'), root.closest('.publish-field')].filter(
    (el): el is Element => el !== null,
  )

  for (const wrapper of wrappers) {
    ;(wrapper as HTMLElement).style.display = 'none'
  }
}

function hasInjectedBadgesInDom(): boolean {
  return document.querySelector('[data-ct-badge]') !== null
}

function hasInjectedTranslateButtonInDom(): boolean {
  return document.querySelector('[data-ct-translate-button]') !== null
}

function tryInject(): void {
  if (injecting || effectiveSites.value.length === 0) return

  injecting = true
  try {
    badgeInjected.value = injectBadges(effectiveSites.value, 'v6')
    buttonInjected.value = injectTranslateButton(effectiveSites.value, 'v6', {
      onClick: openDialog,
      disabled: !hasTargets.value,
    })
  } finally {
    injecting = false
  }
}

watch(
  effectiveSites,
  () => {
    tryInject()
  },
  { deep: true },
)

let unsubscribeMarked: (() => void) | null = null

onMounted(() => {
  if (!hasTargets.value) {
    hideEntireField()
    return
  }

  hideFieldLabelChrome()

  // Attempt immediately (the Sites panel may already be in the DOM)
  tryInject()

  // Keep observing for re-injection when Statamic re-renders the panel.
  observer = new MutationObserver(() => {
    if (injecting) return
    if (badgeInjected.value && hasInjectedBadgesInDom() && buttonInjected.value && hasInjectedTranslateButtonInDom()) {
      return
    }
    tryInject()
  })
  observer.observe(document.body, { childList: true, subtree: true })

  const id = entryId.value
  if (id !== null) {
    unsubscribeMarked = subscribeMarked(id, () => {
      markedCurrentHandles.value = getMarkedHandles(id)
    })
  }
})

onBeforeUnmount(() => {
  observer?.disconnect()
  unsubscribeMarked?.()
  removeBadges()
  removeTranslateButtons()
})

// ── Dialog ────────────────────────────────────────────────────────────────────

function openDialog(): void {
  if (!entryId.value) {
    console.warn('[magic-translator] Cannot open dialog: entry_id is not available.')
    return
  }

  // Pick the default source, preferring the origin site but falling back to
  // the current site if the user has no access to the origin. `sites` is
  // already filtered server-side to the user's accessible sites.
  const accessibleHandles = new Set(sites.value.map((s) => s.handle))
  const defaultSource =
    (originSite.value && accessibleHandles.has(originSite.value) ? originSite.value : null) ??
    (currentSite.value && accessibleHandles.has(currentSite.value) ? currentSite.value : null) ??
    sites.value[0]?.handle ??
    ''

  const dialog = Statamic.$components.append('magic-translator-dialog', {
    props: {
      entryId: entryId.value,
      sourceSite: defaultSource,
      sites: effectiveSites.value,
    },
  })

  dialog.on('close', () => {
    dialog.destroy()
  })
}
</script>

<template>
  <div ref="rootEl" class="magic-translator-fieldtype">
    <template v-if="hasTargets">
      <!-- Translate button -->
      <Button
        v-if="!buttonInjected"
        variant="default"
        size="sm"
        :text="__('magic-translator::messages.translate_button')"
        class="w-full"
        :disabled="!hasTargets"
        @click="openDialog"
      />

      <!--
              Fallback locale status list — shown only when badge injection into
              the native Sites panel has not (yet) succeeded.
          -->
      <div v-if="!badgeInjected && effectiveSites.length > 0" class="mt-3 space-y-1">
        <div v-for="site in effectiveSites" :key="site.handle" class="text-xs flex items-center gap-1.5 py-0.5">
          <span
            class="little-dot shrink-0"
            :class="{
              'bg-green-600': site.exists && !site.is_stale,
              'bg-amber-500': site.is_stale,
              'bg-red-500': !site.exists,
            }"
          />
          <span class="flex-1 truncate">{{ site.name }}</span>
          <span v-if="site.is_stale" class="text-amber-500 shrink-0">⚠</span>
          <span v-else-if="site.last_translated_at" class="text-gray-400 shrink-0">✓</span>
          <span v-else class="text-gray-400 shrink-0">—</span>
        </div>
      </div>
    </template>
  </div>
</template>
