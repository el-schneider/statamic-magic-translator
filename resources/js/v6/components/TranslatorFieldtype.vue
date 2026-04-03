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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { injectBadges, removeBadges, wasPreviouslyInjected } from '../../core/injection'
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
  /** Pre-loaded meta from PHP ContentTranslatorFieldtype::preload() */
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

// ── Badge injection ───────────────────────────────────────────────────────────

/**
 * Whether the badge injection currently succeeds.
 * Stored as a ref so the template can conditionally render the fallback status.
 */
const badgeInjected = ref(wasPreviouslyInjected())

let observer: MutationObserver | null = null
let injecting = false

function hasInjectedBadgesInDom(): boolean {
  return document.querySelector('[data-ct-badge]') !== null
}

function tryInject(): void {
  if (injecting || sites.value.length === 0) return

  injecting = true
  try {
    const succeeded = injectBadges(sites.value, 'v6')
    badgeInjected.value = succeeded
  } finally {
    injecting = false
  }
}

onMounted(() => {
  // Attempt immediately (the Sites panel may already be in the DOM)
  tryInject()

  // Keep observing for re-injection when Statamic re-renders the panel.
  observer = new MutationObserver(() => {
    if (injecting) return
    if (badgeInjected.value && hasInjectedBadgesInDom()) return
    tryInject()
  })
  observer.observe(document.body, { childList: true, subtree: true })
})

onBeforeUnmount(() => {
  observer?.disconnect()
  removeBadges()
})

// ── Dialog ────────────────────────────────────────────────────────────────────

function openDialog(): void {
  if (!entryId.value) {
    console.warn('[content-translator] Cannot open dialog: entry_id is not available.')
    return
  }

  const dialog = Statamic.$components.append('content-translator-dialog', {
    props: {
      entryId: entryId.value,
      sourceSite: originSite.value ?? currentSite.value ?? sites.value[0]?.handle ?? '',
      sites: sites.value,
    },
  })

  dialog.on('close', () => {
    dialog.destroy()
  })
}
</script>

<template>
  <div class="content-translator-fieldtype">
    <!-- Translate button -->
    <button type="button" class="btn btn-sm w-full" :disabled="!hasTargets" @click="openDialog">
      {{ __('content-translator::messages.translate_button') }}
    </button>

    <!--
            Fallback locale status list — shown only when badge injection into
            the native Sites panel has not (yet) succeeded.
        -->
    <div v-if="!badgeInjected && sites.length > 0" class="mt-3 space-y-1">
      <div v-for="site in sites" :key="site.handle" class="text-xs flex items-center gap-1.5 py-0.5">
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
  </div>
</template>
