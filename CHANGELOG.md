# Changelog

All notable changes to `el-schneider/statamic-magic-translator` will be documented in this file.

## v0.1.4 - 2026-07-22

### What's fixed

- Resolve imported fieldsets and referenced field definitions inside Replicator and Bard sets so their nested localized content is extracted and translated. Thanks to @Gitsack for reporting the issue and contributing the fix. (#21, #22)

### Maintenance

- Add `npm run ci` as the shared local and GitHub Actions quality gate, run the full suite before pushes, and require both supported PHP checks before Dependabot patch/minor updates can merge. (#23)

## v0.1.3 - 2026-06-17

### What's fixed

- Publish compiled control panel assets during Statamic's addon install flow so Composer installs copy `resources/dist/build` to `public/vendor/statamic-magic-translator/build`.
- Add regression coverage for install-time asset publishing.

Fixes #15

## v0.1.2 - 2026-05-13

### What's fixed

- Include compiled Vite assets in the package so Composer installs no longer fail with `Vite manifest not found`.
- Fix asset build CI by installing Composer dependencies before `npm ci`, so the local `@statamic/cms` package exists.
- Build assets only against the Statamic v6/PHP 8.4 matrix where the Vite plugin package is available.
- Keep Statamic's fake stache parent directory available during PHP 8.3 test teardown.

### What's changed

- Stop ignoring `resources/dist` so future asset builds can be committed by automation.
- Adjust Dependabot cooldown settings for patch updates.

## v0.1.1 - 2026-04-06

### What's fixed

- Fix DeepL `target_lang` error for locales with regional variants (e.g. `de_DE`, `ar_AR`, `ja_JP`) — only EN, PT, and ZH require regional codes; all others now correctly resolve to the base language code

### What's changed

- Rename environment variables from `CONTENT_TRANSLATOR_*` to `MAGIC_TRANSLATOR_*` to match the package name

## v0.1.0 — Initial release - 2026-04-05

Initial release of **Magic Translator** — translate Statamic entry content across multi-site localizations using LLMs or DeepL, with full support for Bard, Replicator, Grid, and deeply nested content structures.

### Compatibility

- Statamic 5 and 6
- PHP 8.2+
- Any async queue driver with a running worker

## [Unreleased]
