# Changelog

All notable changes to `el-schneider/statamic-magic-translator` will be documented in this file.

## v0.3.0 - 2026-08-12

### What's new

- Custom fieldtypes can opt in to translation. A fieldtype from an addon or your own project is skipped by default, because a plain string is as likely to be a colour swatch or an ID as a meta title. Declare what it holds to have it translated (#33, thanks @stijn-cube):
  
  ```php
  'custom_fieldtypes' => [
      'aardvark_seo_meta_title' => 'plain',
      'my_addon_body' => 'markdown',
  ],
  
  ```

### What's fixed

- Escape unit text before it reaches the DeepL payload. A bare `&` or `<` in a headline made the payload invalid XML, and because units are batched, one character failed every field in the chunk. (#33, thanks @stijn-cube)
- Serialize bard custom mark placeholders as valid XML. `<span data-mark-0>` is a bare attribute, which DeepL's v2 tag handling rejects outright. (#33)
- Reject content XML cannot carry, naming the field instead of letting DeepL fail the whole request with a parse error. Control characters from pasted word processor text are the usual source. (#33)
- Refuse to flatten a field holding structured data. Tier 1 extraction cast any value to a string, so an array became the literal `"Array"` and reassembly wrote that back over the whole sub-array. (#33)
- Decode `&apos;` in translated text, which the HTML 4.01 entity table does not cover. (#33)

### Maintenance

- Bump `actions/checkout`, `actions/setup-node`, `actions/cache`, `dependabot/fetch-metadata` and `git-auto-commit-action`.

## v0.2.1 - 2026-08-08

### What's fixed

- Reject excluded blueprints on the translate endpoint. `mark-current`, the Control Panel action and the CLI planner all honoured `exclude_blueprints`, but a direct request to the translate endpoint did not. (#29)

### Maintenance

- Test against Statamic 5 as well as 6, so the declared `^5.0 || ^6.0` support is actually exercised on every run. (#28)
- Close five coverage gaps found by mutation testing, including the terminal job-failure state and the provider retry classifier, and remove redundant tests. (#29)

## v0.2.0 - 2026-08-07

### What's new

- DeepL glossaries. Set a global `glossary` and per-target-language overrides to enforce your own terminology. Because a glossary is bound to one language pair, an override set to an empty string opts that language out of the global one. Thanks to @stijn-cube. (#26, #27)

### What's fixed

- Support Statamic's Eloquent driver. Numeric entry IDs are normalized before they reach the string-typed planner, Control Panel, endpoint and job boundaries, and the authenticated user is resolved through Statamic's user repository before permission checks and error logging. Thanks to @stijn-cube. (#25, #27)

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
