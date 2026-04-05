# Pre-release pass

> **Status:** done (2026-04-05)

## Goal

Do a final public-release audit of the addon: remove stale/dead code where appropriate, fix polish and consistency issues, simplify/refactor low-risk areas, sync README/docs with actual behavior, and leave the branch in a verifiably clean state.

## Plan

1. **Baseline audit**
   - Run the existing verification suite (`pest`, `pint --test`, `prettier --check`, TypeScript check).
   - Inspect release-facing files (`README.md`, `CHANGELOG.md`, config, command/help text, CP routes, JS entry points) for mismatches.
   - Look for stale markers / dead code candidates / unnecessary duplication.

2. **Tight cleanup pass**
   - Fix real correctness or release-readiness issues found in the audit.
   - Prefer small, low-risk simplifications over broad rewrites.
   - Avoid touching generated artifacts by hand.

3. **Docs + packaging sync**
   - Update README / changelog / inline docs to match the code exactly.
   - Make sure install, publish, queue, CLI, and customization instructions are accurate.
   - Prune or update AGENTS.md if I uncover non-discoverable gotchas or stale guidance.

4. **Final verification**
   - Re-run targeted/full checks after changes.
   - Build assets if frontend sources changed.
   - Summarize what changed, what was intentionally left alone, and any remaining follow-up items.

## Findings + outcome

### Fixed

- Replaced hardcoded frontend `/cp/...` API paths with Statamic's `cp_url()` helper and named the addon CP routes, so custom control-panel prefixes work.
- Updated the CLI queue-dispatch hint to use `cp_route('magic-translator.status')`.
- Consolidated shared TypeScript globals into `resources/js/core/globals.d.ts`, removing duplicate ambient declarations and the bogus `axios` type dependency.
- Excluded `worktrees/` from the addon TypeScript program so local sibling worktrees no longer pollute checks.
- Removed two dead exception classes that were never thrown or referenced.
- Removed the corresponding unused frontend/backend i18n error keys.
- Synced README/CHANGELOG with the actual code:
  - correct views publish tag
  - correct published config path
  - correct default Prism model
  - clarified async behavior (CP async, CLI sync or async)
- Added `.prettierignore` entries for internal planning/research docs, the lockfile, and JSON fixtures to keep formatting checks focused on source files.

### Verified

- `./vendor/bin/pest`
- `./vendor/bin/pint --test`
- `npm run format:check`
- `node --stack_size=16384 ./node_modules/typescript/bin/tsc --noEmit`
- `npm run build`
- Browser smoke test in both sandboxes:
  - v6: opened `Pages -> Home`, Translate button present, dialog opens
  - v5: opened `Pages -> Home`, Translate button present, dialog opens
- Independent adversarial review: **No bugs found**
