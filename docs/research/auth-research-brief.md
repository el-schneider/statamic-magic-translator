The research brief is written to `/Users/vvjlis/dev/packages/statamic/statamic-content-translator/statamic-content-translator/docs/research/auth-research-brief.md`. Here's a summary of the key findings:

---

## Key Findings

### Permission Model
- **Site access:** `access {site_handle} site` — dynamically registered per-site in `CorePermissions::registerSites()` (CorePermissions.php:84). Check via `$user->can('view', $site)`.
- **Entry edit:** `edit {collection_handle} entries` + `edit other authors {collection_handle} entries` in a permission tree.
- **Critical:** `$user->can('edit', $entry)` → `EntryPolicy::edit()` → first checks `$this->userCanAccessSite($user, $entry->site())`. So it enforces **source site** access only, not target site access.

### 7 Gaps Found

1. **`TranslateEntryAction::visibleTo()`** — hides only on `Site::all()->count() <= 1`, not filtered by user access
2. **`TranslateEntryAction::authorize()`** — only checks source-site access, not target-site
3. **`TranslationController::trigger()`** — same gap as above at the API layer
4. **`ContentTranslatorFieldtype::preload()`** — returns all `Site::all()` unfiltered
5. **`TranslatorFieldtype.vue`** — button shows when `targetSites.length > 0` using unfiltered sites
6. **`TranslationDialog.vue`** — checkboxes allow selecting inaccessible locales
7. **`TranslateEntryAction::run()`** — passes all `Site::all()` to the bulk-action JS callback

### Canonical CP Pattern
```php
$collection
    ->sites()
    ->filter(fn ($handle) => $user->can('view', Site::get($handle)))
```
This is exactly what `EntriesController::getAuthorizedSitesForCollection()` (EntriesController.php:500) does for the native locale switcher.

### Frontend
`Statamic.can('access default site')` exposes the flat permissions array. Permissions with resolved `{site}` arguments are already baked in (e.g., `["access default site", "access german site", ...]`).