# Statamic Content Translator — Design Document

## Overview

A Statamic addon that translates entry content across multi-site localizations. Uses LLMs (via Prism) or DeepL as translation backends. All processing is async via queued jobs. Provides a custom fieldtype with dialog-based UI for dispatching translations, plus bulk actions on entry listings.

**Statamic compatibility:** v5 + v6.

---

## Architecture

### Core Pattern: Flatten → Batch Translate → Reassemble

Translation of a single entry to a single target locale follows three phases:

**Phase 1 — Extract.** Recursively walk the entry's data guided by its blueprint. For every translatable piece of text, produce a `TranslationUnit` with a recorded path and format:

```php
TranslationUnit(path: 'title', text: 'My Blog Post', format: 'plain')
TranslationUnit(path: 'content.0.content', text: 'We\'re building a <b>simple</b> website.', format: 'html', markMap: [...])
TranslationUnit(path: 'content.1.attrs.values.caption', text: 'A nice photo', format: 'plain')
TranslationUnit(path: 'content.1.attrs.values.blocks.0.quote', text: 'Such dread...', format: 'plain')
TranslationUnit(path: 'meta_description', text: 'A post about building', format: 'plain')
```

The extraction handles arbitrary nesting depth. A Bard inside a Replicator inside a Bard produces a longer list — the recursion lives only in extraction, translation is always flat.

**Phase 2 — Translate.** Send all units to the translation service in one batch. Both backends receive full entry context:
- **LLMs via Prism:** all units in a single prompt; structured output enforces `[{id, text}]` response shape.
- **DeepL:** units concatenated into a single text with `<u id="N">` delimiters; `tag_handling: "html"` preserves structure; split back by tags after.

Configurable `max_units_per_request` for chunking large entries (DeepL has a 50-text limit, LLM context windows have practical limits). Default: single batch per entry.

**Phase 3 — Reassemble.** Walk the translated units, use recorded `path` to set each value back into a deep clone of the original data. For `html` format units, parse inline tags back into ProseMirror content arrays. Everything else (structure, marks, custom nodes, attributes) was never serialized.

---

## Content Structure Handling

### Field Classification

Fields are classified by their blueprint fieldtype. The addon translates all fields by default; individual fields can be excluded via a `translatable: false` property on the field config in the blueprint.

**Tier 1 — Flat text (translate the string):**
`text`, `textarea`, `markdown`

**Tier 2 — Structural containers (recursive walk):**
- `replicator` — array of typed sets. Walk each set, look up its fields in the blueprint, recurse.
- `grid` — array of rows. Walk each row, look up column fields in the blueprint, recurse.
- `table` — cells array contains strings, translate each cell.

**Tier 3 — Bard (hybrid approach):**
See [Bard handling](#bard-handling) below.

**Tier 4 — Skip entirely:**
`assets`, `toggle`, `integer`, `float`, `date`, `color`, `code`, `select`, `radio`, `checkboxes`, `entries`, `terms`, `users`, `video`, `yaml`, `template`, `section`

**Borderline:**
- `slug` — optionally regenerated from translated title (user chooses per-translation via dialog checkbox).
- `link` — translate the `text` property, preserve the `url`.

### Bard Handling

Bard fields store content as ProseMirror JSON with interlaced "sets." An HTML round-trip (ProseMirror → HTML → translate → HTML → ProseMirror) is unsafe because TipTap PHP silently drops unknown node/mark types (custom extensions like Bard Texstyle's `btsSpan`, `btsDiv`, `btsPin`).

**Approach: keep the ProseMirror tree structure untouched, only rebuild the `content` arrays of block-level nodes.**

For each block node (paragraph, heading, list item, etc.):

1. **Serialize** its `content` array into an inline-tagged string:
   `We're building a <b>simple</b> website.`
   Custom marks (e.g., `btsSpan`) get indexed placeholder tags:
   `<span data-mark-0 class="brand-text">styled text</span>`

2. **Collect** all block-level strings in document order, separated by `\n\n`. Sets become `<x-set-N/>` placeholders between blocks.

3. **Translate** the whole blob as a single unit (preserving context across paragraphs).

4. **Parse back**: split by `\n\n`, parse inline tags within each block back into ProseMirror text nodes + marks using the recorded mark map.

5. **Replace** only the `content` array of each block node. Everything else — node types, attributes, custom nodes, sets — preserved byte-for-byte.

For sets within Bard: extract their fields and recurse back to the appropriate tier. A set containing another Bard field triggers another tier-3 extraction at that level.

---

## Translation Service Contract

```php
interface TranslationService
{
    /**
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]  Same units with translated text
     */
    public function translate(array $units, string $sourceLocale, string $targetLocale): array;
}
```

Always batch, always pass all context. The caller never thinks about single vs batch.

### Prism Backend (LLMs)

Uses `echolabsdev/prism` (or `prism-php/prism`). Builds a single prompt with all translation units, uses Prism's structured output to enforce `[{id, text}]` response shape.

Provider/model configurable via config:

```php
'prism' => [
    'provider' => env('CONTENT_TRANSLATOR_PROVIDER', 'anthropic'),
    'model' => env('CONTENT_TRANSLATOR_MODEL', 'claude-sonnet-4-20250514'),
],
```

### DeepL Backend

Uses `deeplcom/deepl-php` (official SDK). Units concatenated with `<u id="N">` delimiters, sent as single text with `tag_handling: "html"`. Translation-specific features exposed:

```php
'deepl' => [
    'api_key' => env('DEEPL_API_KEY'),
    'formality' => 'default',
    'overrides' => [
        'de' => ['formality' => 'prefer_more'],
        'ja' => ['formality' => 'prefer_more'],
    ],
],
```

### Extension Point

The `TranslationService` contract is the extension point for custom backends (Google Translate, etc.). Users bind their own implementation in a service provider.

---

## Prompt System

Prompt content lives in **Blade views** (multi-line prose, composable with `@include`, publishable). Config acts as the routing table pointing to views.

### Config Structure

```php
'prism' => [
    'provider' => env('CONTENT_TRANSLATOR_PROVIDER', 'anthropic'),
    'model' => env('CONTENT_TRANSLATOR_MODEL', 'claude-sonnet-4-20250514'),
    'prompts' => [
        'system' => 'content-translator::prompts.system',
        'user' => 'content-translator::prompts.user',
        'overrides' => [
            'ja' => ['system' => 'content-translator::prompts.system-ja'],
            'de' => ['system' => 'content-translator::prompts.system-de'],
        ],
    ],
],
```

### View Resolution

Language-specific override exists → use it. Otherwise → fall back to default view.

### Available View Variables

- `$sourceLocaleName` — e.g., "English"
- `$targetLocaleName` — e.g., "Japanese"
- `$sourceLocale` — e.g., "en"
- `$targetLocale` — e.g., "ja"

### Example Views

```blade
{{-- resources/views/prompts/system.blade.php --}}
You are a professional translator. Translate accurately from {{ $sourceLocaleName }} to {{ $targetLocaleName }}.

@include('content-translator::prompts.partials.tag-rules')

Preserve the exact structure of the input. Do not add explanations or commentary.
```

```blade
{{-- resources/views/prompts/partials/tag-rules.blade.php --}}
The input contains HTML formatting tags (<b>, <i>, <a>, <span>, etc.) and structural placeholders (<x-set-N/>).
You MUST preserve all tags exactly as they appear. Do not translate tag attributes. Do not add or remove tags.
```

Users publish and override via `php artisan vendor:publish --tag=content-translator-views`.

---

## Job Architecture

### One Job Per Entry-Locale Pair

Each translation dispatches as a separate queued job, allowing parallel processing.

```php
final class TranslateEntryJob implements ShouldQueue
{
    public function __construct(
        private readonly string $entryId,
        private readonly string $targetSite,
        private readonly array $options = [],  // generate_slug, overwrite, etc.
    ) {}
}
```

The job:
1. Loads the origin entry
2. Runs Phase 1 (extract)
3. Runs Phase 2 (translate via configured service)
4. Runs Phase 3 (reassemble)
5. Creates or updates the localized entry
6. Sets `last_translated_at` on the localized entry
7. Reports status (success/failure) for polling

### Queue Configuration

```php
'queue' => [
    'connection' => env('CONTENT_TRANSLATOR_QUEUE_CONNECTION'),
    'name' => env('CONTENT_TRANSLATOR_QUEUE_NAME'),
],
```

---

## Staleness Detection

Each translated entry stores a `last_translated_at` timestamp (set by the job on successful translation). Compared against the origin entry's `updated_at` to determine staleness.

States:
- **Missing** — localization doesn't exist.
- **Translated** — `last_translated_at` is set and ≥ origin's `updated_at`.
- **Outdated** — `last_translated_at` < origin's `updated_at`.
- **Manual** — localization exists but `last_translated_at` is not set (manually created/edited).

---

## UI Design

### Sidebar Fieldtype

A custom fieldtype (`content_translator`) added to the blueprint's sidebar section. Stores no data. Renders a "Translate" button that opens the translation dialog.

### Badge Injection into Locale Switcher

On mount, the fieldtype's Vue component locates the native Sites panel in the DOM and injects small status badges per locale row:

```
┌─ Sites ──────────────────────────────┐
│ 🟢 English                [Active]   │
│ 🟢 French       2h ago              │
│ 🔴 German       —                    │
│ 🟢 Japanese     ⚠️ outdated         │
└──────────────────────────────────────┘
```

- Uses `MutationObserver` to re-inject after SPA locale switching.
- `localStorage` caches whether injection succeeded — prevents layout shift on subsequent loads.
- Falls back gracefully: if the Sites panel DOM can't be found, the fieldtype renders its own standalone status display.

### Translation Dialog

Shared component used from both the sidebar fieldtype and bulk actions.

**Single entry mode:**

```
┌─ Translate ──────────────────────────────────────┐
│                                                    │
│  Source: English (origin)                          │
│                                                    │
│  ☑ 🇫🇷 French      ✅ 2h ago                      │
│  ☑ 🇩🇪 German      ◌ translating...               │
│  ☐ 🇯🇵 Japanese    ⚠️ Rate limited  [Retry]       │
│                                                    │
│  Options                                           │
│  ☑ Generate slugs from translated title            │
│  ☐ Overwrite existing translations                 │
│                                                    │
│              [Cancel]  [Translate selected]         │
└────────────────────────────────────────────────────┘
```

- Locales with existing translations are unchecked by default (prevent accidents).
- Missing locales are checked by default.
- Each row has its own compact status: spinner → ✓ → ⚠️ with inline error + retry.
- "Overwrite existing" toggle gates selection of already-translated locales.

**Bulk action mode (N entries selected):**

```
┌─ Translate 12 entries ───────────────────────┐
│                                               │
│  ☑ 🇫🇷 French      ● 8/12  ⚠️ 1 failed      │
│  ☑ 🇩🇪 German      ◌ queued                  │
│  ☐ 🇯🇵 Japanese    —                         │
│                                               │
│  Options                                      │
│  ☐ Overwrite existing translations            │
│                                               │
│            [Cancel]  [Translate selected]      │
└───────────────────────────────────────────────┘
```

- Per-locale rows with compact counters (`8/12`) and per-locale spinners.
- Each locale's jobs are independent; one failing doesn't block others.

### Polling

Backend exposes trigger + check endpoints (same pattern as auto-alt-text):

- `POST /cp/content-translator/translate` — dispatches job(s), returns job IDs.
- `GET /cp/content-translator/status?jobs[]=...` — returns per-job status for polling.

Frontend polls at ~1s intervals with backoff. Configurable timeout.

---

## Bulk Actions

A Statamic Action registered for entries:

```php
final class TranslateEntryAction extends Action
{
    public static function title(): string { ... }

    public function run($items, $values): array
    {
        // Returns callback to open the shared translation dialog
        return ['callback' => ['openTranslationDialog', $items->pluck('id')]];
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry
            && Site::all()->count() > 1;
    }
}
```

Uses Statamic's `callback` return mechanism to open the shared dialog from JS.

---

## Config Overview

```php
// config/content-translator.php

return [
    'service' => env('CONTENT_TRANSLATOR_SERVICE', 'prism'), // 'prism' or 'deepl'

    'prism' => [
        'provider' => env('CONTENT_TRANSLATOR_PROVIDER', 'anthropic'),
        'model' => env('CONTENT_TRANSLATOR_MODEL', 'claude-sonnet-4-20250514'),
        'prompts' => [
            'system' => 'content-translator::prompts.system',
            'user' => 'content-translator::prompts.user',
            'overrides' => [
                // 'ja' => ['system' => 'content-translator::prompts.system-ja'],
            ],
        ],
    ],

    'deepl' => [
        'api_key' => env('DEEPL_API_KEY'),
        'formality' => 'default',
        'overrides' => [
            // 'de' => ['formality' => 'prefer_more'],
        ],
    ],

    'max_units_per_request' => null, // null = no limit, integer = chunk size

    'queue' => [
        'connection' => env('CONTENT_TRANSLATOR_QUEUE_CONNECTION'),
        'name' => env('CONTENT_TRANSLATOR_QUEUE_NAME'),
    ],

    'log_completions' => true,

    'timestamp_field' => 'last_translated_at',
];
```

---

## Phase 2 Considerations (Future)

- **Per-field staleness tracking** — a dedicated `_translation_meta` data structure on each localized entry, storing per-unit `translated_at` timestamps and origin content hashes. Enables detecting exactly which fields drifted since last translation. Phase 1's `TranslationUnit` paths are the natural keys for this ledger — recording hashes during reassembly is a small extension.
- **Field actions** — inline "translate" / "outdated" indicators on individual translatable fields in the publish form, powered by the per-field staleness data. Allows translating a single field without re-translating the whole entry.
- **Field-level selection** — dialog expands per-locale rows to show individual fields with checkboxes. The extraction phase already produces per-field units with paths; the UI just needs to expose them.
- **Translation memory** — cache previous translations to avoid re-translating unchanged content.
- **Glossary support** — DeepL has native glossaries; for LLMs, inject glossary terms into the prompt.
- **Diff view** — show what changed in the origin since last translation.
- **Webhook/event notifications** — notify external systems when translations complete.
