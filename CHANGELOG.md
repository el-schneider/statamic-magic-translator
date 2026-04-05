# Changelog

All notable changes to `el-schneider/statamic-magic-translator` are documented in this file.

## [Unreleased]

### Added

- **Artisan command `statamic:magic-translator:translate`** — flexible CLI tool for bulk, CI-driven, and surgical translation. Supports filtering by collection, entry ID, blueprint, and target sites; dry-run preview; interactive confirmation with `-n` bypass; sync execution or async queue dispatch via `--dispatch-jobs`; plus `--include-stale` and `--overwrite` re-translation modes.

### Changed

- Frontend API calls now use Statamic's CP URL helper instead of hardcoding `/cp`, so custom control-panel prefixes work correctly.
- Shared TypeScript globals were consolidated and `worktrees/` is excluded from the addon TypeScript program to keep checks focused on the actual package.

### Removed

- Dead exception scaffolding for `ProviderNotConfiguredException` and `TranslationDispatchException` that was never emitted by the runtime.

### Fixed

- README examples now match the actual published config path, views publish tag, and default Prism model.
