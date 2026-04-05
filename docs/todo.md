# TODO — remove `collections` opt-in and support wildcard blueprint exclusions

## Goal

Make translation UI default-on for all entry collections, with opt-out via `exclude_blueprints` patterns (including wildcards like `pages.*`).

## Execution Plan

1. Update config surface in `config/content-translator.php`:
   - Remove `collections` option and related docs comments.
   - Clarify `exclude_blueprints` supports exact and wildcard patterns (`collection.blueprint`, `collection.*`, `*.*`).
2. Update runtime behavior:
   - `ServiceProvider` blueprint injection should no longer gate by collection list.
   - `ServiceProvider` entry-saving listener should no longer gate by collection list.
   - Add shared matcher for `exclude_blueprints` with wildcard support.
3. Update action visibility logic:
   - `TranslateEntryAction::visibleTo()` should only require Entry + multisite + not excluded by blueprint pattern.
4. Update tests and docs:
   - Rewrite feature tests that assert configured/unconfigured collection behavior.
   - Add tests for wildcard exclusions (`articles.*`) and exact exclusions.
   - Update README configuration section to document default-on + exclusions.
5. Verify:
   - `./vendor/bin/pest tests/Feature/ServiceProviderTest.php tests/Feature/TranslateEntryActionTest.php`
   - `./vendor/bin/pest`
   - `./vendor/bin/pint --test`

## Replan Triggers

- Statamic event payload lacks reliable blueprint handle in a path we can evaluate for action visibility.
- Wildcard matching semantics in Laravel (`Str::is`) create unexpected exclusions for dot-notation handles.
