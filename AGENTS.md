**Golden Rule: maintain AGENTS.md as you work.** Every pitfall you document saves future agents and humans from repeating the same mistake. Every stale entry left behind erodes trust in the file. Keep entries minimal and terse — only what can't be discovered by reading the code. When you hit a new gotcha, add it. When a root cause gets fixed, delete the line.

## Project Overview

**Magic Translator** — Translate Statamic content

Package: `el-schneider/statamic-magic-translator`

> ⚠️ **Pre-v1, active development.** Backwards compatibility is generally **not** a reason to hold back changes — breaking changes are acceptable and expected. **Remove this notice from AGENTS.md as soon as v1 is released.**

## Local Environment

Sandboxes, URLs, ports, and credentials are machine-specific and live in an untracked `AGENTS.local.md` (in this directory or a parent). Read it before running the addon in a Statamic app.

## Development Commands

### Code Quality

```bash
npm run check   # prettier --check, pint --test
npm run fix     # the same two, writing
```

### Testing

```bash
./vendor/bin/pest
./vendor/bin/pest --filter=SomeTest
```

### Running Artisan

Run artisan from the Statamic app that has this addon installed (see `AGENTS.local.md`).

### Async / Queue Testing

Translations dispatched to the queue need a running worker. Before testing async behavior, ensure a queue listener is running in the background for the target sandbox:

```bash
php <sandbox>/artisan queue:listen --tries=1 --timeout=0 &
```

Check first: `pgrep -f 'queue:listen'` — only start one if none is running.

## Contributing

- Comments say why, not what changed. History belongs in the PR.
- UI changes: verify in a real browser (agent-browser, Chrome DevTools) and say what you checked. No browser automation available — ask, don't guess.
- Add nothing you can derive or reuse.
- Fix the cause, not the reported symptom.
- No abstraction with a single caller.
- Let failures surface. No try/catch for tidiness.

## Off-Limits Files

- **`resources/dist/`** — Built by CI on push to `main`. Do NOT commit build output.
- **`CHANGELOG.md`** — Updated by CI on release. Do NOT edit.

## Gotchas

- In tests, `Entry::blueprint()->handle()` may resolve to the collection handle unless an explicit blueprint is created first. Create the blueprint when asserting exact `exclude_blueprints` matches.
- Feature tests already extend `Tests\TestCase` via `tests/Pest.php`; adding `uses(Tests\TestCase::class)` again in a Feature test file causes a Pest duplicate-test-case error.
- In tests, `makeLocalization('<site>')->save()` fails with `Call to a member function lang() on null` if the site handle is not configured in Statamic's active sites. Use configured handles (default test setup includes `en`/`fr`) or set sites first.
- In `EntrySaving` listeners, `getOriginal('<field>')` can already equal current values on cached entry instances because `Entry::save()` calls `Entry::find($id)` (which syncs originals). In tests that assert original-vs-current diffs, clone the entry before mutating/saving.
- Tests that exercise `TranslateEntry` or translation commands without mocking `TranslationService` will make real provider API calls. `phpunit.xml` force-blanks `OPENAI_API_KEY`/`ANTHROPIC_API_KEY`/`DEEPL_API_KEY` so such tests fail with 401 in CI. Always bind a mock (`bindPrefixService()` in `TranslateCommandTest` or `Prism::fake()` in `PrismTranslationServiceTest`) before the command runs.
