# Design: Artisan `content-translator:translate` Command

**Date:** 2026-04-05
**Status:** Design approved, ready for implementation
**Related:** Modeled after `statamic:auto-alt:generate` in `el-schneider/statamic-auto-alt-text`

## Purpose

Provide a flexible artisan command for triggering translations outside the CP. Supports all three primary use cases equally:

1. **Initial bulk translation** — site just installed the addon, translate hundreds of existing entries to new locales.
2. **Ongoing CI/cron automation** — translate missing or stale entries on a schedule.
3. **Targeted / surgical re-translation** — fix specific entries after a prompt or config change.

One general-purpose command with a rich filter vocabulary, safe-by-default behavior, and preview-then-confirm UX.

## Command Signature

```bash
please statamic:content-translator:translate
    {--to=*             : Target site handle (repeatable). Default: all sites each entry supports (minus source)}
    {--from=            : Source site handle (default: entry's origin)}
    {--collection=*     : Filter by collection handle (repeatable)}
    {--entry=*          : Filter by entry ID (repeatable)}
    {--blueprint=*      : Filter by blueprint handle (repeatable)}
    {--include-stale    : Also re-translate entries where source updated after target last_translated_at}
    {--overwrite        : Re-translate everything regardless of existing state (nuclear option)}
    {--generate-slug    : Slugify translated title}
    {--dispatch-jobs    : Dispatch queue jobs instead of running synchronously}
    {--dry-run          : Show the plan without executing, exit 0}
```

Standard Laravel/Symfony `-n` / `--no-interaction` is honored automatically (skips the scope-confirm prompt).

**Signature prefix** — `statamic:content-translator:translate`, matching `statamic:auto-alt:generate` (`statamic:{addon-slug}:{verb}` convention).

**Uses `RunsInPlease` trait** → invokable via both `php artisan` and `php please`.

**Requires at least one filter** (`--to`, `--collection`, `--entry`, or `--blueprint`). A bare invocation errors out with help text — prevents "translate the entire universe" typos.

## Default Behavior

Safe-by-default. When a target localization already exists, **skip it** unless one of:

- `--include-stale` → also process stale targets (source updated after target's `last_translated_at`).
- `--overwrite` → process everything regardless of state (nuclear option).

Missing target localizations are **always created** when they pass the filters. The CP action retains its current `overwrite=true` default — asymmetric defaults are fine; each caller passes its own `options[]` to `TranslateEntry::handle()`.

## Selection & Resolution

**Entry selection pipeline** (progressive narrowing):

1. If `--entry=*` provided → `Entry::find($id)` for each; warn+skip unknown IDs.
2. Else if `--collection=*` provided → query entries across those collections.
3. Else → query all entries in all collections.
4. Apply `--blueprint=*` filter (narrows by `entry->blueprint()->handle()`).
5. Apply blueprint exclusions from `content-translator.php` config (same rules as CP).

**Source site resolution:**

- If `--from=` → use it; error if the entry doesn't have that localization.
- Else → `$entry->origin() ?? $entry`.

**Target sites per entry:**

- If `--to=*` → intersection with `$entry->collection()->sites()`; warn once per target that no entries support.
- Else → `$entry->collection()->sites()` minus source.

**Unknown handle handling:**

- Unknown `--collection` / `--blueprint` / `--to` → **error and abort** (fail fast).
- Unknown `--entry` ID → **warn and skip** (lenient; IDs come from humans, partial runs are useful).

**State filters** (applied last, per entry × site pair):

- Skip if target exists AND no `--include-stale` AND no `--overwrite`.
- If `--include-stale` → require target to actually be stale (source updated > target `last_translated_at`).
- If `--overwrite` → always process.

## Preview → Confirm → Execute Flow

Default flow always builds + prints the plan first, then prompts.

```
$ please statamic:content-translator:translate --collection=pages --to=de --to=fr

Content Translator — translation plan
─────────────────────────────────────────────────────────────
Filters:       collection=pages, blueprint=*, to=[de,fr]
Source site:   default (auto)
Mode:          safe (skip existing; --include-stale / --overwrite off)

Resolved:  47 entries × 2 target sites = 94 candidate pairs

Breakdown:
  ✓ 83  will translate          (target localization missing)
  ⊘ 11  skip — already exists   (pass --include-stale or --overwrite to process)
  ⚠  0  skip — unsupported site (entry's collection excludes target)
─────────────────────────────────────────────────────────────
Effective work: 83 translations (sync mode, ~est. 14m @ 10s/job)

Proceed? [yes/no]:
```

**Flag control:**

- `--dry-run` → print plan, exit 0. Never prompts, never executes.
- `-n` / `--no-interaction` → skip prompt, go straight to execute. Required for CI.
- Default (interactive TTY) → prompt; user types `yes` to proceed.

**Non-interactive safety:** if stdin is not a TTY and `-n` isn't set → error out with "Refusing to run non-interactively without -n / --no-interaction". Prevents accidental cron-pipe damage.

## Reporting

**Sync mode:**

- Progress bar format: `X/83 — translating entry "Foo" → de`.
- Failures **do not abort** — logged via `TranslationLogger`, collected in memory.
- Real-time warnings printed above the progress bar for failures.
- Final summary table:

```
Translation summary
───────────────────────────────────────────────
✓ Succeeded:   78
✗ Failed:       5
───────────────────────────────────────────────

Failures:
  entry-abc123 → de  ProviderRateLimitedException: 429 from OpenAI
  entry-def456 → fr  ProviderResponseInvalidException: schema mismatch
  entry-ghi789 → de  TranslationDispatchException: missing origin
  ...
Full details written to: storage/logs/content-translator-{timestamp}.log
```

**Async mode (`--dispatch-jobs`):**

- Shows dispatch summary with job IDs and polling hint.
- Skips "est. time" line.
- Exits 0 immediately.

Example:
```
Dispatched 83 jobs to queue "default".
Track status: GET /cp/content-translator/status?jobs[]=...
Or run: php artisan queue:work
```

**Exit codes:**

- `0` — full success, dry-run, or user declined.
- `1` — partial failure (some translations failed).
- `2` — command-level error (bad args, unknown collection/site/blueprint).

## Implementation Architecture

**New files:**

```
src/Commands/TranslateCommand.php      # ~150 lines — command glue (auto-loaded by Statamic)
src/Console/TranslationPlanner.php     # ~120 lines — filters → plan (pure, testable)
src/Console/TranslationPlan.php        # ~40 lines — plan collection with helpers
src/Console/PlanItem.php               # ~25 lines — DTO
src/Console/PlanAction.php             # ~15 lines — enum: Translate, SkipExists, SkipUnsupported, Stale, Overwrite
```

**No ServiceProvider changes.** Statamic's `AddonServiceProvider::bootCommands()` auto-scans `src/Commands/` and `src/Console/Commands/` for classes extending `Illuminate\Console\Command` (see `vendor/statamic/cms/src/Providers/AddonServiceProvider.php:408-420`). The command auto-registers on drop.

**Existing code reused unchanged:**

- `TranslateEntry::handle($entryId, $targetSite, $sourceSite, $options)` — sync execution.
- `TranslateEntryJob::dispatch(...)` — async execution.
- `BlueprintExclusions::contains(...)` — same exclusion rules as CP.
- `content-translator.php` — queue config, exclusion patterns.
- `TranslationLogger` — append failure details.

**Planner contract (sketch):**

```php
final class TranslationPlanner
{
    public function __construct(
        private readonly BlueprintExclusions $exclusions,
    ) {}

    public function plan(FilterCriteria $filters): TranslationPlan
    {
        // 1. Resolve entries (collection/entry/blueprint filters)
        // 2. For each entry × target site: determine PlanAction
        // 3. Return TranslationPlan (collection of PlanItems)
    }
}
```

**Why the extracted planner:**

- Unit-testable in isolation (`$planner->plan($filters)` asserts exact `PlanItem[]`).
- Reusable if we later expose preview via REST (e.g. a CP "preview bulk translate" panel).
- Keeps the command class thin: parse → plan → confirm → execute → report.

## Test Strategy

**Planner unit tests** (high value — pure logic):

- Filter combinations: `--collection` only, `--entry` only, `--blueprint` only, combinations.
- Blueprint exclusions applied correctly.
- Stale detection: target exists but source newer → stale.
- Overwrite semantics: all targets processed when flag set.
- Unknown-handle handling: collection missing → error; entry missing → warn + skip.
- Target site resolution: `--to=*` intersection with entry's supported sites.
- Source site resolution: `--from=` validation, fallback to origin.

**Command feature tests** (via `$this->artisan(...)->expectsOutput(...)`):

- Happy path: plan → confirm → execute → summary.
- `--dry-run`: prints plan, exits 0, no jobs dispatched.
- `-n`: skips prompt, executes directly.
- Non-TTY without `-n`: errors out.
- Failure collection: one entry fails, command reports partial failure, exit 1.
- Async dispatch: `--dispatch-jobs` queues jobs, exits 0.
- Invalid args: unknown collection → exit 2.

**No changes needed** to existing tests for `TranslateEntry`, `TranslateEntryJob`, etc. — they remain correct.

## Open Questions

None — all design decisions resolved in brainstorming.

## Next Steps

1. Create feature branch (e.g. `feat/artisan-translate-command`).
2. Implement in order: enum → DTOs → Planner → Command → tests.
3. Add command usage section to README.
4. Add entry to CHANGELOG.
