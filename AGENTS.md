**Golden Rule: maintain AGENTS.md as you work.** Every pitfall you document saves future agents and humans from repeating the same mistake. Every stale entry left behind erodes trust in the file. Keep entries minimal and terse — only what can't be discovered by reading the code. When you hit a new gotcha, add it. When a root cause gets fixed, delete the line.

This file provides guidance to coding agents when working with code in this repository.

## Project Overview

**Magic Translator** — Translate Statamic content

Package: `el-schneider/statamic-magic-translator`

> ⚠️ **Pre-v1, active development.** Backwards compatibility is generally **not** a reason to hold back changes — breaking changes are acceptable and expected. **Remove this notice from AGENTS.md as soon as v1 is released.**

## Sandbox Environments

This addon has companion Statamic sandboxes for testing. They may live as **siblings** or this addon may be **nested inside** a sandbox's `addons/` directory.

### Sibling layout (typical)

```
../statamic-magic-translator/              # ← you are here
../statamic-magic-translator-test/         # Statamic v5 sandbox
../statamic-magic-translator-test-v6/      # Statamic v6 sandbox
```

### Nested layout (alternative)

```
./                              # Statamic sandbox root
└── addons/
    └── el-schneider/
        └── statamic-magic-translator/     # ← you are here
```

**How to detect:** Check if `../../artisan` or `../../../artisan` exists — if so, you're nested inside a sandbox.

### Sandbox URLs

| Version | URL                                             |
| ------- | ----------------------------------------------- |
| v5      | `http://statamic-magic-translator-test.test`    |
| v6      | `http://statamic-magic-translator-test-v6.test` |

**Credentials:** `agent@agent.md` / `agent`

## Development Commands

### Code Quality

```bash
./vendor/bin/pint --test
./vendor/bin/pint
```

### Testing

```bash
./vendor/bin/pest
./vendor/bin/pest --filter=SomeTest
```

### Running Artisan from the Addon Directory

If sibling layout: `php ../statamic-magic-translator-test/artisan {command}`
If nested layout: `php ../../artisan {command}` (or `../../../artisan`)

### Async / Queue Testing

Translations dispatched to the queue need a running worker. Before testing async behavior, ensure a queue listener is running in the background for the target sandbox:

```bash
php ../statamic-magic-translator-test/artisan queue:listen --tries=1 --timeout=0 &
```

Check first: `pgrep -f 'queue:listen'` — only start one if none is running.

## Off-Limits Files

- **`vendor/`** — Managed by Composer.
- **`dist/`** — Built assets. Rebuild, don't hand-edit.

## Gotchas

- In tests, `Entry::blueprint()->handle()` may resolve to the collection handle unless an explicit blueprint is created first. Create the blueprint when asserting exact `exclude_blueprints` matches.
- Feature tests already extend `Tests\TestCase` via `tests/Pest.php`; adding `uses(Tests\TestCase::class)` again in a Feature test file causes a Pest duplicate-test-case error.
- `npx tsc --noEmit` can crash with `RangeError: Maximum call stack size exceeded` in this workspace (Node 22 + current include set). Use `node --stack_size=16384 ./node_modules/typescript/bin/tsc --noEmit` to inspect actual TS errors.
- If a sibling `worktrees/` directory exists, `tsc --noEmit` may report unrelated errors from that tree; filter diagnostics to `^resources/js` to compare against the known 5 baseline addon errors.
- In tests, `makeLocalization('<site>')->save()` fails with `Call to a member function lang() on null` if the site handle is not configured in Statamic's active sites. Use configured handles (default test setup includes `en`/`fr`) or set sites first.
- In `EntrySaving` listeners, `getOriginal('<field>')` can already equal current values on cached entry instances because `Entry::save()` calls `Entry::find($id)` (which syncs originals). In tests that assert original-vs-current diffs, clone the entry before mutating/saving.
- Tests that exercise `TranslateEntry` or translation commands without mocking `TranslationService` will make real provider API calls. `phpunit.xml` force-blanks `OPENAI_API_KEY`/`ANTHROPIC_API_KEY`/`DEEPL_API_KEY` so such tests fail with 401 in CI. Always bind a mock (`bindPrefixService()` in `TranslateCommandTest` or `Prism::fake()` in `PrismTranslationServiceTest`) before the command runs.
