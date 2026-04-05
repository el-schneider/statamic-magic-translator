# Mark Current — escape hatch for "outdated" staleness flag

> **Status: planned** (2026-04-05)

## Problem

Today, a localization is flagged `is_stale` by a single timestamp comparison:

```php
$isStale = $rootEntry->lastModified() > Carbon::parse($meta['last_translated_at']);
```

`lastModified()` fires on **any** source entry save, so trivial edits (typo fix, publish toggle, SEO tweak, image swap on a non-translatable field) mark every target locale as outdated. The only way to clear the flag is to re-run the AI translation, which overwrites any human polish on the target.

## Goal

Make staleness reflect whether the **translatable content** actually changed, and give the user a conscious escape hatch for edge cases.

## Design

### 1. Content fingerprint replaces timestamp comparison

Each localization's `magic_translator` metadata gains one field:

```yaml
magic_translator:
  last_translated_at: "2025-01-15T10:30:00+00:00"
  source_content_hash: "v1:sha256:abc123..."
```

`source_content_hash` = hash of the source entry's **extracted translation units** (`ContentExtractor` output) at the moment this locale was last translated or marked current.

Staleness check becomes:

```php
$currentSourceHash = hash(extract(source_now));
$isStale = $target.source_content_hash !== $currentSourceHash;
```

**Missing hash = fall back to current timestamp behavior** (pre-existing locales continue to work; first retranslate migrates them).

**Hash format:** `v{version}:sha256:{hex}`. Version prefix lets us bump extraction logic without catastrophic invalidation. v1 = sha256 over canonical JSON of `[{path, text, format}, ...]` sorted by path.

### 2. Auto-refresh on manual target edits

Hook `EntrySaved` for **non-origin localizations**:

1. Compute extracted units from `$entry->getOriginal()` (previous data).
2. Compute extracted units from current `$entry->data()`.
3. If they differ → user touched translatable content → update:
   - `last_translated_at = now()`
   - `source_content_hash = hash(extract(current_source))`

If only non-translatable fields changed → no-op. Staleness check won't fire anyway because source hash is unchanged.

**Guard against recursion:** the `TranslateEntry` action already writes the metadata itself; detect & skip via a Blink flag or by checking whether the saving event came from our own pipeline.

### 3. Explicit "Mark current" — two entry points

**A. Dialog button (direct action, no confirm)**

Per stale/manual locale row in the translation dialog, add a "Mark current" button to the right. Clicking it:
- POSTs to `magic-translator/mark-current` with `{entry_id, locale}`
- No confirmation — user is already in an explicit translation workflow

**B. Native Sites panel (clickable badge, confirm modal)**

The DOM-injected "⚠ outdated" badge in Statamic's native locale switcher becomes a `<button>`. Clicking it:
- Dispatches `CustomEvent('magic-translator:request-mark-current', { detail: { siteHandle } })`
- The already-mounted `TranslatorFieldtype.vue` component listens, shows a confirmation modal
- On confirm: calls the same endpoint

Only `is_stale` and `manual` (exists but no timestamp) badges become buttons. `current`/missing badges remain inert.

### 4. CLI planner parity

`TranslationPlanner::isStale()` uses the same hash-comparison logic. Single source of truth.

---

## Implementation

### Data & hashing

1. **New file: `src/Support/ContentFingerprint.php`**
   - `compute(array $data, array $fields): string` → runs `ContentExtractor::extract()`, canonicalizes `TranslationUnit[]` to sorted array, `json_encode` → `sha256` → prefixes `v1:sha256:`.
   - `HASH_VERSION = 'v1'` constant.

2. **New file: `src/Support/SourceHashCache.php`** (thin wrapper over Blink)
   - `get(Entry $source): string` — caches by `"magic-translator:src-hash:{id}:{lastModified->timestamp}"`.
   - Computed once per request, reused across N target comparisons.

### Backend

3. **`src/Fieldtypes/MagicTranslatorFieldtype.php`** — replace the `lastModified` comparison:
   ```php
   $sourceHash = $hashCache->get($rootEntry, $collectionBlueprintFields);
   $isStale = ($meta['source_content_hash'] ?? null) !== $sourceHash;
   // Fallback: if no stored hash AND last_translated_at exists, compare timestamps (legacy).
   ```

4. **`src/Console/TranslationPlanner.php::isStale()`** — same replacement.

5. **`src/Actions/TranslateEntry.php`** (step 13) — write both fields:
   ```php
   $meta['last_translated_at'] = now()->toIso8601String();
   $meta['source_content_hash'] = ContentFingerprint::compute($sourceData, $fieldDefs);
   ```

6. **New listener: `src/Listeners/RefreshLocaleHashOnSave.php`**
   - Subscribed to `EntrySaved`.
   - Skip if `$entry->isRoot()` (origin save doesn't refresh targets).
   - Skip if Blink flag `"magic-translator:saving:{id}"` is set (our own pipeline).
   - Extract old + new translatable units; if different, set `last_translated_at = now()` and `source_content_hash = hash(current source)` on this localization.
   - Register in `ServiceProvider`.

7. **New controller method: `TranslationController::markCurrent`**
   - Route: `POST magic-translator/mark-current`
   - Input: `{entry_id, locale}`
   - Authz: reuse the existing per-site authorization check (user must have access to `locale`).
   - Loads localization, computes current source hash, writes `last_translated_at = now()` + `source_content_hash`, saves.
   - Returns 200 with updated meta for that locale.

8. **`routes/cp.php`** — add the route.

### Frontend

9. **`resources/js/core/injection.ts`**
   - `createBadge()`: when `is_stale` or `manual`, create a `<button>` instead of `<span>`, styled identically, with click handler that dispatches the custom event.
   - Export `removeBadges` remains unchanged.

10. **`resources/js/v6/components/TranslatorFieldtype.vue` + `resources/js/v5/addon.ts`**
    - Add `window.addEventListener('magic-translator:request-mark-current', handler)` in `onMounted`, remove in `onBeforeUnmount`.
    - Handler opens a Statamic confirmation modal ("Mark <site> as current?"). On confirm: POST to `mark-current`, on success: update local `meta` state (Vue reactivity re-triggers injection via the existing watcher).

11. **`resources/js/v6/components/TranslationDialog.vue` + `resources/js/v5/addon.ts`**
    - For each site where `is_stale || (exists && !last_translated_at)`, render a small "Mark current" button on the right side of the row (next to the "outdated" badge).
    - Direct API call, no confirm. On success: update local state so the badge/dot flips to "current".

12. **`resources/lang/en/messages.php`** — add keys:
    - `mark_current_button`, `mark_current_confirm_title`, `mark_current_confirm_body`, `mark_current_success`, `mark_current_failed`.

### Tests

13. **Unit**
    - `ContentFingerprintTest` — stable hash for identical data, different hash for different translatable content, same hash when only non-translatable fields differ.
    - `SourceHashCacheTest` — cached within request, invalidated when `lastModified` changes.

14. **Feature**
    - Staleness: source edit on non-translatable field → `is_stale = false`. Edit on translatable field → `is_stale = true`.
    - Auto-refresh listener: save target localization touching translatable field → hash + timestamp updated. Save touching non-translatable field → untouched.
    - Mark current endpoint: authorized user can mark; unauthorized returns 403; endpoint updates both fields and returns fresh meta.
    - TranslationPlanner: same hash-based staleness logic holds (test mirrors fieldtype test).
    - TranslateEntry: after run, both `last_translated_at` and `source_content_hash` present on target.

15. **Regression**
    - Legacy fallback: localization with `last_translated_at` but no `source_content_hash` uses timestamp comparison (existing behavior preserved).

---

## Open questions (answered during brainstorm)

- ✅ Hash scope: extracted units, not raw field values.
- ✅ Auto-refresh trigger: `EntrySaved` + `getOriginal()` diff on translatable extraction.
- ✅ CLI parity: yes.
- ✅ Version prefix: yes, `v1:sha256:...`.
- ✅ UI: native locale switcher badge becomes confirm-prompted button; dialog gets direct-action "Mark current" button.
- ✅ Migration: no special handling, fall back to timestamp comparison when hash absent; Jonas fixes local test data manually.

## Non-goals

- Bulk "mark all locales current" on the source entry — leave for later if needed.
- Audit log of mark-current actions.
- Config flag to disable the escape hatch.
- Distinguishing "reviewed without editing" from "actually edited" — a click on Mark Current is sufficient signal.
