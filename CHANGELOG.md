# Changelog

All notable changes to `el-schneider/statamic-magic-translator` will be documented in this file.

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
