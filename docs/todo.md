# Translation Dialog — Collection Sites + Authorization Fix

> **Status: implemented** (2026-04-05). Commits f0f993a → 616738e. See
> `docs/research/auth-research-brief.md` for the research this plan was
> built on.



## Problem

The translation dialog currently ignores two things:

1. **Collection site config** — it shows every site in `resources/sites.yaml` instead of only the sites the entry's collection allows.
2. **User authorization** — any user who can see the Translate button can dispatch jobs into any locale, regardless of whether they can access/edit that site.

Both flaws exist in the sidebar fieldtype flow AND the bulk action flow, and the API controller trusts whatever the dialog submits.

## Statamic's permission model (research summary)

### Permissions involved

| Permission | Where registered | Check |
|---|---|---|
| `access {site} site` | `vendor/statamic/cms/src/Auth/CorePermissions.php:84` (only when `Site::multiEnabled()`) | `$user->can('view', Site::get($handle))` |
| `edit {collection} entries` | `CorePermissions.php` (nested under `view {collection} entries`) | `$user->hasPermission("edit {$handle} entries")` |
| `edit other authors {collection} entries` | same tree | same pattern |

### Critical: `$user->can('edit', $entry)` only covers the **source** site

`EntryPolicy::edit()` (`vendor/statamic/cms/src/Policies/EntryPolicy.php:38`) calls `$this->userCanAccessSite($user, $entry->site())` — which checks the **entry's current site**, not target sites. Our controller's current `$user->can('edit', $entry)` check is therefore a source-site guard only.

### The Statamic CP precedent we should copy

`EntriesController::getAuthorizedSitesForCollection()` (`vendor/statamic/cms/src/Http/Controllers/CP/Collections/EntriesController.php:500`):

```php
$collection
    ->sites()
    ->filter(fn ($handle) => User::current()->can('view', Site::get($handle)));
```

This is used to build the native locale switcher in the sidebar. **We should match exactly**.

### Frontend permission access

- `Statamic.user.permissions` — array of resolved strings like `["access default site", "access german site", "edit blog entries"]` (permission `{site}` templates are already expanded per-site at render time — see `JavascriptComposer.php:99-103`).
- `Statamic.can('access german site')` — vanilla helper, auto-passes for `super` (`bootstrap/statamic.js:312-315`).
- Super users: both layers already short-circuit (`Permission.js:6`, server-side `$user->can()` via Laravel gates).

---

## Design decisions

### What "can translate INTO site X" means

A user can be chosen as a target-site recipient for collection C iff **all** of:

1. Multi-site is enabled (otherwise the whole feature is moot — we already check this in the bulk action and should in the fieldtype).
2. Site X is in `$collection->sites()`.
3. `$user->can('view', Site::get(X))` — i.e. has `access X site` or is super.
4. `$user->hasPermission("edit {$collectionHandle} entries")` OR is super. *(We check this once at the collection level — if you can edit entries in this collection on **any** site, you can edit them on any site you can access.)*

We intentionally **do not** check `edit other authors` per-entry here. The translation job runs async, often writes `author` fields, and mirrors the behavior of Statamic's CP locale-switcher which also doesn't filter by author. If this becomes an issue we revisit.

### Source site is always the entry's locale

The dialog currently lets the user change the source via a dropdown. Post-change: source must also satisfy `access {source} site` AND the entry must exist in that locale. We keep the existing `$user->can('edit', $entry)` check since it already enforces source-site access for the specific entry.

### Bulk mode: intersection across collections

If a bulk selection spans collections (rare but possible), the allowed targets are the **intersection** of per-collection allowed target sites. If any collection lacks `edit … entries` permission, that collection's entries are **filtered out** of the bulk selection before dispatch — we don't silently skip them, we reject the whole action.

Actually — simpler: since `authorize($user, $item)` runs per-item and currently uses `can('edit', $item)`, we keep that. The **dialog filter** (targets) intersects across all items; the **server enforcement** per-job catches anything that slips through.

### Button visibility

Hide the Translate button (sidebar + bulk action) when the user has **zero accessible target sites** for this entry's collection. This is stricter than the current `Site::all()->count() <= 1` check.

---

## Implementation plan

### Phase 1 — Backend: compute accessible sites

**New helper:** `src/Support/AccessibleSites.php`

```php
final class AccessibleSites
{
    /**
     * Target sites a user may translate INTO for a given collection.
     * Returns a Collection<int, string> of site handles.
     */
    public static function forTranslationTargets(
        User $user,
        Collection $collection,
        ?string $excludeSource = null,
    ): \Illuminate\Support\Collection;

    /**
     * Sites the user can access at all, intersected with the collection.
     * Used for "should we even show the button" checks.
     */
    public static function forCollection(
        User $user,
        Collection $collection,
    ): \Illuminate\Support\Collection;
}
```

Logic:
- If `! Site::multiEnabled()` → empty collection.
- If user lacks `edit {handle} entries` on the collection → empty collection.
- Else: `$collection->sites()->filter(fn ($h) => $user->can('view', Site::get($h)))`.
- `forTranslationTargets()` additionally strips `$excludeSource`.

Tests: `tests/Unit/Support/AccessibleSitesTest.php` — matrix:
- super user
- user with full access
- user with `edit entries` but no `access site`
- user with `access site` but no `edit entries`
- single-site setup
- source exclusion

### Phase 2 — Fieldtype preload

**`src/Fieldtypes/ContentTranslatorFieldtype.php`**

- In `preload()`, after resolving `$entry` and `$rootEntry`, compute `$user = User::current()` and `$allowedHandles = AccessibleSites::forCollection($user, $entry->collection())`.
- Filter `Site::all()` by `$allowedHandles` before the `map()` that builds `sitesData`.
- **Always include the current/origin site** in the returned payload even if the user doesn't have edit permission for it — the badges still need to render for display purposes. Actually: just filter to `$allowedHandles` ∪ `{current_site}` so the user always sees their own row. (Reconsider: simpler to filter strictly and let the fieldtype not render when empty.)
- **Decision:** filter strictly. If the user has no accessible sites in the collection, return `sites: []` and the component already handles that via `hasTargets`.
- Blueprint-editor fallback (no entry) stays on `Site::all()` — no auth context.

Tests: extend `tests/Unit/Fieldtypes/ContentTranslatorFieldtypeTest.php`:
- super user sees all collection sites
- restricted user sees only accessible subset
- user with no access to any collection site sees empty list
- collection-not-enabled-for-site filter still works

### Phase 3 — Bulk action

**`src/StatamicActions/TranslateEntryAction.php`**

- `visibleTo()`:
  - Keep the `Site::all()->count() <= 1` guard.
  - Keep blueprint exclusion.
  - **Add:** `AccessibleSites::forTranslationTargets($user, $item->collection(), $item->locale())->isNotEmpty()`.
  - `$user` comes from `User::current()`.
- `authorize()`:
  - Keep `$user->can('edit', $item)` (source-site enforcement).
  - **Add:** per-item: at least one accessible target site exists.
- `run()`:
  - Compute the intersection of allowed targets across all selected items' collections.
  - Pass only that intersection to the JS callback instead of `Site::all()`.

Tests: `tests/Feature/TranslateEntryActionTest.php`:
- `visibleTo` hidden when no accessible targets
- bulk run passes only intersection
- cross-collection intersection logic

### Phase 4 — Controller enforcement

**`src/Http/Controllers/TranslationController.php::trigger()`**

After the existing `$user->can('edit', $entry)` check, add:

```php
$allowed = AccessibleSites::forTranslationTargets($user, $entry->collection(), $entry->locale());

$forbidden = array_values(array_diff($validated['target_sites'], $allowed->all()));

if ($forbidden !== []) {
    return response()->json([
        'success' => false,
        'error' => [
            'code' => 'forbidden',
            'message' => "Not authorized to translate into: ".implode(', ', $forbidden),
            'retryable' => false,
        ],
    ], 403);
}
```

Also validate `source_site`:
- If provided, must satisfy `access {source_site} site` for this user (i.e. in `AccessibleSites::forCollection()` output).
- Entry must exist in that locale (currently unchecked — silent failure downstream).

Tests: `tests/Feature/Http/TranslationControllerTest.php`:
- 403 when target not in user's accessible sites
- 403 when source_site not accessible
- 422 / 404 when entry doesn't exist in source_site
- super user passes all combinations
- single-site mode (if feature is even reachable)

### Phase 5 — Frontend: add user context to preload

**`src/Fieldtypes/ContentTranslatorFieldtype.php`** — extend preload payload:

```php
return [
    'entry_id' => ...,
    'current_site' => ...,
    'origin_site' => ...,
    'is_origin' => ...,
    'sites' => $sitesData,              // already filtered in Phase 2
    'accessible_target_sites' => $allowedHandles->values()->all(),  // for future-proofing
];
```

(Probably redundant since `sites` is already filtered — include only if we find a need during implementation. Lean: skip, revisit if needed.)

**`resources/js/core/types.ts`** — no change needed if we don't add the new field. If we do add it, extend `FieldtypePreload`.

### Phase 6 — Frontend: dialog + fieldtype

**`resources/js/v6/components/TranslatorFieldtype.vue`** — no code change needed; it already derives `hasTargets` from `sites`, which Phase 2 narrows server-side.

**`resources/js/v6/components/TranslationDialog.vue`** — no code change needed; it filters source from targets via `targetSites` computed, and receives a pre-filtered list.

**Single manual verification:** confirm that changing the source dropdown to a site the user cannot access is prevented. Currently `sourceOptions` is built from `props.sites` which is already filtered — so the source dropdown only shows accessible sites. ✅

### Phase 7 — v5 parity

Check if there are any v5-specific components that duplicate v6 logic. From the tree: `resources/js/v5/addon.ts` exists but no v5-specific TranslationDialog or TranslatorFieldtype component files appear — the components are shared. So v5 gets the fixes for free.

Confirm during implementation by grepping for `TranslationDialog` and `TranslatorFieldtype` under `resources/js/v5/`.

---

## Test matrix (manual browser verification)

Sandboxes: `statamic-content-translator-test.test` (v5) and `statamic-content-translator-test-v6.test` (v6), login `agent@agent.md` / `agent`.

Create a test user role with:
- `access default site` ✓
- `access german site` ✓
- `access french site` ✗
- `edit pages entries` ✓
- `edit other authors pages entries` ✗

Expected dialog behavior for a `pages` entry in `default`:
- Targets shown: `german` only.
- `french` hidden.
- If collection `pages` is only configured for `default + german + french`, `french` still hidden.
- If user is stripped of `edit pages entries`, button does not render.
- If user is stripped of `access german site`, `german` hidden (targets empty → button hidden).

POST to `/cp/content-translator/translate` with `target_sites: ["french"]` → 403.

---

## Out of scope (for this PR)

- Author-based filtering (`edit other authors`) per-entry — revisit if editors complain.
- Granular "write to {site}" permission beyond `access {site} site` — Statamic doesn't distinguish.
- UI to explain *why* a locale is hidden — just hide cleanly, match CP behavior.
- Queue worker identity — jobs run without a user context; authorization happens exclusively at the controller boundary.

---

## Ordering & commits

1. `feat: add AccessibleSites support helper` + tests
2. `fix: restrict fieldtype preload to collection sites` (also the non-auth collection-sites fix that started this)
3. `fix: restrict fieldtype preload to user-accessible sites`
4. `fix: restrict bulk action to authorized targets`
5. `fix: enforce target-site authorization in translation controller`
6. Manual browser verification in both sandboxes
7. Update CHANGELOG / README if documented

Each commit has green tests. No squash.
