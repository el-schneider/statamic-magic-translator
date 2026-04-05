# Changelog

All notable changes to `el-schneider/statamic-content-translator` are documented in this file.

## [Unreleased]

### Added

- **Artisan command `statamic:content-translator:translate`** — flexible CLI tool for bulk, CI-driven, and surgical translation. Supports filtering by collection, entry ID, blueprint, and target sites; dry-run preview; interactive confirmation with `-n` bypass; sync execution or async queue dispatch via `--dispatch-jobs`; plus `--include-stale` and `--overwrite` re-translation modes.
