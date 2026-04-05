# Artisan Translate Command Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a flexible `statamic:content-translator:translate` artisan command that supports initial bulk translation, CI/cron automation, and surgical re-translation through a rich filter vocabulary.

**Architecture:** Single command file in `src/Commands/` (auto-loaded by Statamic's `AddonServiceProvider::bootCommands()`). Filter → plan resolution extracted to a testable `TranslationPlanner` in `src/Console/`. Command orchestrates: parse options → build plan → print preview → confirm → execute (sync or async dispatch) → report. Reuses existing `TranslateEntry` action, `TranslateEntryJob`, `BlueprintExclusions`, and `TranslationLogger`.

**Tech Stack:** Laravel Artisan Command, Symfony Console, Statamic 5/6 Entry/Collection/Site facades, Pest 2, PHPUnit 10.

**Reference implementation:** `el-schneider/statamic-auto-alt-text` — see `src/Commands/GenerateAltTextCommand.php`.

**Design doc:** `docs/plans/2026-04-05-artisan-translate-command-design.md`

---

## Branch

Already created: `feat/artisan-translate-command`.

---

## Phase 1: Data Types

### Task 1: PlanAction enum + PlanItem DTO

**Files:**
- Create: `src/Console/PlanAction.php`
- Create: `src/Console/PlanItem.php`
- Test: `tests/Unit/Console/PlanItemTest.php`

**Step 1: Write the failing test**

Create `tests/Unit/Console/PlanItemTest.php`:

```php
<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Console\PlanAction;
use ElSchneider\ContentTranslator\Console\PlanItem;

it('constructs a PlanItem with all fields', function () {
    $item = new PlanItem(
        entryId: 'entry-abc',
        entryTitle: 'Hello World',
        collection: 'articles',
        sourceSite: 'en',
        targetSite: 'de',
        action: PlanAction::Translate,
        reason: 'target localization missing',
    );

    expect($item->entryId)->toBe('entry-abc');
    expect($item->entryTitle)->toBe('Hello World');
    expect($item->collection)->toBe('articles');
    expect($item->sourceSite)->toBe('en');
    expect($item->targetSite)->toBe('de');
    expect($item->action)->toBe(PlanAction::Translate);
    expect($item->reason)->toBe('target localization missing');
});

it('exposes willProcess() semantics per action', function () {
    $translate = new PlanItem('e', 't', 'c', 'en', 'de', PlanAction::Translate, '');
    $stale = new PlanItem('e', 't', 'c', 'en', 'de', PlanAction::Stale, '');
    $overwrite = new PlanItem('e', 't', 'c', 'en', 'de', PlanAction::Overwrite, '');
    $skipExists = new PlanItem('e', 't', 'c', 'en', 'de', PlanAction::SkipExists, '');
    $skipUnsupported = new PlanItem('e', 't', 'c', 'en', 'de', PlanAction::SkipUnsupported, '');

    expect($translate->willProcess())->toBeTrue();
    expect($stale->willProcess())->toBeTrue();
    expect($overwrite->willProcess())->toBeTrue();
    expect($skipExists->willProcess())->toBeFalse();
    expect($skipUnsupported->willProcess())->toBeFalse();
});
```

**Step 2: Run test — verify it fails**

```bash
./vendor/bin/pest --filter=PlanItemTest
```
Expected: FAIL with "Class ElSchneider\ContentTranslator\Console\PlanAction not found"

**Step 3: Create the enum**

Create `src/Console/PlanAction.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

enum PlanAction: string
{
    case Translate = 'translate';        // target missing — will create
    case Stale = 'stale';                // target exists but source newer — will re-translate
    case Overwrite = 'overwrite';        // target exists — will re-translate (nuclear flag)
    case SkipExists = 'skip_exists';     // target exists, no re-translate flag set
    case SkipUnsupported = 'skip_unsupported'; // entry's collection doesn't support target site

    public function willProcess(): bool
    {
        return match ($this) {
            self::Translate, self::Stale, self::Overwrite => true,
            self::SkipExists, self::SkipUnsupported => false,
        };
    }
}
```

**Step 4: Create the DTO**

Create `src/Console/PlanItem.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

final readonly class PlanItem
{
    public function __construct(
        public string $entryId,
        public string $entryTitle,
        public string $collection,
        public string $sourceSite,
        public string $targetSite,
        public PlanAction $action,
        public string $reason,
    ) {}

    public function willProcess(): bool
    {
        return $this->action->willProcess();
    }
}
```

**Step 5: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=PlanItemTest
```
Expected: 2 passed.

**Step 6: Commit**

```bash
git add src/Console/PlanAction.php src/Console/PlanItem.php tests/Unit/Console/PlanItemTest.php
git commit -m "feat: add PlanAction enum and PlanItem DTO for translate command"
```

---

### Task 2: TranslationPlan collection

**Files:**
- Create: `src/Console/TranslationPlan.php`
- Test: `tests/Unit/Console/TranslationPlanTest.php`

**Step 1: Write the failing test**

Create `tests/Unit/Console/TranslationPlanTest.php`:

```php
<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Console\PlanAction;
use ElSchneider\ContentTranslator\Console\PlanItem;
use ElSchneider\ContentTranslator\Console\TranslationPlan;

function makeItem(PlanAction $action, string $id = 'e', string $site = 'de'): PlanItem
{
    return new PlanItem($id, 'Title', 'articles', 'en', $site, $action, '');
}

it('reports total count', function () {
    $plan = new TranslationPlan([
        makeItem(PlanAction::Translate),
        makeItem(PlanAction::SkipExists),
        makeItem(PlanAction::Stale),
    ]);

    expect($plan->count())->toBe(3);
});

it('counts items grouped by action', function () {
    $plan = new TranslationPlan([
        makeItem(PlanAction::Translate),
        makeItem(PlanAction::Translate),
        makeItem(PlanAction::SkipExists),
        makeItem(PlanAction::Stale),
        makeItem(PlanAction::SkipUnsupported),
    ]);

    expect($plan->countByAction())->toBe([
        'translate' => 2,
        'skip_exists' => 1,
        'stale' => 1,
        'skip_unsupported' => 1,
    ]);
});

it('returns only processable items', function () {
    $plan = new TranslationPlan([
        makeItem(PlanAction::Translate, 'a'),
        makeItem(PlanAction::SkipExists, 'b'),
        makeItem(PlanAction::Stale, 'c'),
        makeItem(PlanAction::SkipUnsupported, 'd'),
        makeItem(PlanAction::Overwrite, 'e'),
    ]);

    $processable = $plan->processable();

    expect($processable)->toHaveCount(3);
    expect(array_map(fn ($i) => $i->entryId, $processable))->toBe(['a', 'c', 'e']);
});

it('exposes isEmpty()', function () {
    expect((new TranslationPlan([]))->isEmpty())->toBeTrue();
    expect((new TranslationPlan([makeItem(PlanAction::Translate)]))->isEmpty())->toBeFalse();
});

it('exposes unique collection set', function () {
    $plan = new TranslationPlan([
        new PlanItem('e1', 'T', 'articles', 'en', 'de', PlanAction::Translate, ''),
        new PlanItem('e2', 'T', 'articles', 'en', 'fr', PlanAction::Translate, ''),
        new PlanItem('e3', 'T', 'pages', 'en', 'de', PlanAction::Translate, ''),
    ]);

    expect($plan->collections())->toBe(['articles', 'pages']);
});

it('exposes unique target site set', function () {
    $plan = new TranslationPlan([
        new PlanItem('e1', 'T', 'articles', 'en', 'de', PlanAction::Translate, ''),
        new PlanItem('e2', 'T', 'articles', 'en', 'fr', PlanAction::Translate, ''),
        new PlanItem('e3', 'T', 'articles', 'en', 'de', PlanAction::Translate, ''),
    ]);

    expect($plan->targetSites())->toBe(['de', 'fr']);
});
```

**Step 2: Run test — verify it fails**

```bash
./vendor/bin/pest --filter=TranslationPlanTest
```
Expected: FAIL with "Class not found".

**Step 3: Implement the collection**

Create `src/Console/TranslationPlan.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

/**
 * Immutable collection of PlanItems with summary helpers.
 */
final readonly class TranslationPlan
{
    /**
     * @param  PlanItem[]  $items
     */
    public function __construct(public array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Return a keyed array mapping action value → count.
     *
     * @return array<string, int>
     */
    public function countByAction(): array
    {
        $counts = [];
        foreach ($this->items as $item) {
            $key = $item->action->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Return only items that will actually be processed (translate/stale/overwrite).
     *
     * @return PlanItem[]
     */
    public function processable(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (PlanItem $i): bool => $i->willProcess(),
        ));
    }

    /**
     * @return string[]
     */
    public function collections(): array
    {
        return array_values(array_unique(array_map(
            static fn (PlanItem $i): string => $i->collection,
            $this->items,
        )));
    }

    /**
     * @return string[]
     */
    public function targetSites(): array
    {
        return array_values(array_unique(array_map(
            static fn (PlanItem $i): string => $i->targetSite,
            $this->items,
        )));
    }
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslationPlanTest
```
Expected: 6 passed.

**Step 5: Commit**

```bash
git add src/Console/TranslationPlan.php tests/Unit/Console/TranslationPlanTest.php
git commit -m "feat: add TranslationPlan collection with summary helpers"
```

---

## Phase 2: Planner

### Task 3: FilterCriteria DTO

**Files:**
- Create: `src/Console/FilterCriteria.php`
- Test: `tests/Unit/Console/FilterCriteriaTest.php`

**Step 1: Write the failing test**

Create `tests/Unit/Console/FilterCriteriaTest.php`:

```php
<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Console\FilterCriteria;

it('constructs with all fields', function () {
    $criteria = new FilterCriteria(
        targetSites: ['de', 'fr'],
        sourceSite: 'en',
        collections: ['articles'],
        entryIds: ['abc-123'],
        blueprints: ['default'],
        includeStale: true,
        overwrite: false,
    );

    expect($criteria->targetSites)->toBe(['de', 'fr']);
    expect($criteria->sourceSite)->toBe('en');
    expect($criteria->collections)->toBe(['articles']);
    expect($criteria->entryIds)->toBe(['abc-123']);
    expect($criteria->blueprints)->toBe(['default']);
    expect($criteria->includeStale)->toBeTrue();
    expect($criteria->overwrite)->toBeFalse();
});

it('reports whether any selector filter is set', function () {
    $empty = new FilterCriteria([], null, [], [], [], false, false);
    expect($empty->hasAnySelectorFilter())->toBeFalse();

    $withTo = new FilterCriteria(['de'], null, [], [], [], false, false);
    expect($withTo->hasAnySelectorFilter())->toBeTrue();

    $withCollection = new FilterCriteria([], null, ['articles'], [], [], false, false);
    expect($withCollection->hasAnySelectorFilter())->toBeTrue();

    $withEntry = new FilterCriteria([], null, [], ['abc'], [], false, false);
    expect($withEntry->hasAnySelectorFilter())->toBeTrue();

    $withBlueprint = new FilterCriteria([], null, [], [], ['default'], false, false);
    expect($withBlueprint->hasAnySelectorFilter())->toBeTrue();
});
```

**Step 2: Run test — verify it fails**

```bash
./vendor/bin/pest --filter=FilterCriteriaTest
```
Expected: FAIL, class not found.

**Step 3: Implement the DTO**

Create `src/Console/FilterCriteria.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

/**
 * Immutable input to TranslationPlanner.
 *
 * All array fields use string[] of handles/IDs. Empty arrays mean "no filter applied
 * on this axis" — e.g. empty $collections means "all collections".
 */
final readonly class FilterCriteria
{
    /**
     * @param  string[]  $targetSites    Empty → resolve per-entry from its collection sites
     * @param  string[]  $collections    Empty → all collections
     * @param  string[]  $entryIds       Empty → no entry-ID narrowing
     * @param  string[]  $blueprints     Empty → no blueprint narrowing
     */
    public function __construct(
        public array $targetSites,
        public ?string $sourceSite,
        public array $collections,
        public array $entryIds,
        public array $blueprints,
        public bool $includeStale,
        public bool $overwrite,
    ) {}

    public function hasAnySelectorFilter(): bool
    {
        return $this->targetSites !== []
            || $this->collections !== []
            || $this->entryIds !== []
            || $this->blueprints !== [];
    }
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=FilterCriteriaTest
```
Expected: 2 passed.

**Step 5: Commit**

```bash
git add src/Console/FilterCriteria.php tests/Unit/Console/FilterCriteriaTest.php
git commit -m "feat: add FilterCriteria DTO for translation planner"
```

---

### Task 4: TranslationPlanner — entry selection

**Files:**
- Create: `src/Console/TranslationPlanner.php`
- Test: `tests/Feature/Console/TranslationPlannerTest.php`

Planner tests require Statamic bootstrap (Entry/Collection/Site facades), so they live in `Feature/`. Test file opts into `Tests\TestCase` via `uses()`.

**Step 1: Write the failing test**

Create `tests/Feature/Console/TranslationPlannerTest.php`:

```php
<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Console\FilterCriteria;
use ElSchneider\ContentTranslator\Console\PlanAction;
use ElSchneider\ContentTranslator\Console\TranslationPlanner;
use Statamic\Facades\Entry;
use Tests\StatamicTestHelpers;

uses(Tests\TestCase::class, StatamicTestHelpers::class);

function makeCriteria(array $overrides = []): FilterCriteria
{
    return new FilterCriteria(
        targetSites: $overrides['targetSites'] ?? [],
        sourceSite: $overrides['sourceSite'] ?? null,
        collections: $overrides['collections'] ?? [],
        entryIds: $overrides['entryIds'] ?? [],
        blueprints: $overrides['blueprints'] ?? [],
        includeStale: $overrides['includeStale'] ?? false,
        overwrite: $overrides['overwrite'] ?? false,
    );
}

it('returns empty plan when no entries exist', function () {
    $this->createTestCollection('articles', ['en', 'de']);

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria(['targetSites' => ['de']]));

    expect($plan->isEmpty())->toBeTrue();
});

it('plans one pair per entry × target site', function () {
    $this->createTestCollection('articles', ['en', 'de', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de', 'fr'],
        'sourceSite' => 'en',
    ]));

    expect($plan->count())->toBe(2);
    expect($plan->targetSites())->toBe(['de', 'fr']);

    foreach ($plan->items as $item) {
        expect($item->entryId)->toBe($entry->id());
        expect($item->action)->toBe(PlanAction::Translate);
        expect($item->sourceSite)->toBe('en');
    }
});

it('filters by --collection', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestCollection('pages', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestBlueprint('pages');
    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'article-1');
    $this->createTestEntry(collection: 'pages', site: 'en', slug: 'page-1');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'collections' => ['articles'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->collections())->toBe(['articles']);
});

it('filters by --entry IDs', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $e1 = $this->createTestEntry(collection: 'articles', site: 'en', slug: 'a');
    $e2 = $this->createTestEntry(collection: 'articles', site: 'en', slug: 'b');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'entryIds' => [$e1->id()],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->entryId)->toBe($e1->id());
});
```

**Step 2: Run test — verify it fails**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: FAIL, planner class not found.

**Step 3: Implement the planner skeleton**

Create `src/Console/TranslationPlanner.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

use ElSchneider\ContentTranslator\Support\BlueprintExclusions;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

final class TranslationPlanner
{
    /**
     * Build a translation plan from filter criteria.
     *
     * Resolution order:
     *   1. Resolve candidate entries (entry IDs → collections → all collections)
     *   2. Apply --blueprint filter + BlueprintExclusions
     *   3. For each entry, resolve target sites (explicit --to, or entry's collection sites minus source)
     *   4. For each entry × target site, classify the action (translate/stale/overwrite/skip)
     *
     * @throws InvalidArgumentException on unknown collection/blueprint/site handles
     */
    public function plan(FilterCriteria $filters): TranslationPlan
    {
        $this->assertKnownHandles($filters);

        $entries = $this->resolveEntries($filters);

        $items = [];
        foreach ($entries as $entry) {
            foreach ($this->resolveTargetSites($entry, $filters) as $targetSite) {
                $items[] = $this->classify($entry, $targetSite, $filters);
            }
        }

        return new TranslationPlan($items);
    }

    /**
     * @return iterable<EntryContract>
     */
    private function resolveEntries(FilterCriteria $filters): iterable
    {
        // Start with explicit IDs when provided.
        if ($filters->entryIds !== []) {
            foreach ($filters->entryIds as $id) {
                $entry = EntryFacade::find($id);
                if ($entry === null) {
                    // Lenient: caller will surface the warning; planner just drops.
                    continue;
                }
                yield $entry;
            }

            return;
        }

        // Otherwise query by collection (or all collections).
        $collections = $filters->collections !== []
            ? $filters->collections
            : CollectionFacade::handles()->all();

        foreach ($collections as $collectionHandle) {
            foreach (EntryFacade::query()->where('collection', $collectionHandle)->get() as $entry) {
                // Only yield the root/origin so we don't double-process localizations.
                yield $entry->hasOrigin() ? $entry->root() : $entry;
            }
        }
    }

    /**
     * @return string[]
     */
    private function resolveTargetSites(EntryContract $entry, FilterCriteria $filters): array
    {
        $collectionSites = $entry->collection()->sites()->all();
        $source = $this->resolveSourceSite($entry, $filters);

        if ($filters->targetSites !== []) {
            // Intersection: only process requested targets the collection supports.
            return array_values(array_intersect($filters->targetSites, $collectionSites));
        }

        // Default: all collection sites minus source.
        return array_values(array_filter(
            $collectionSites,
            static fn (string $site): bool => $site !== $source,
        ));
    }

    private function resolveSourceSite(EntryContract $entry, FilterCriteria $filters): string
    {
        if ($filters->sourceSite !== null) {
            return $filters->sourceSite;
        }

        // Walk to the origin/root entry's site.
        $root = $entry->hasOrigin() ? $entry->root() : $entry;

        return $root->locale();
    }

    private function classify(EntryContract $entry, string $targetSite, FilterCriteria $filters): PlanItem
    {
        $source = $this->resolveSourceSite($entry, $filters);
        $title = (string) ($entry->get('title') ?? $entry->id());

        // Placeholder — Task 6 will refine state classification.
        return new PlanItem(
            entryId: $entry->id(),
            entryTitle: $title,
            collection: $entry->collectionHandle(),
            sourceSite: $source,
            targetSite: $targetSite,
            action: PlanAction::Translate,
            reason: 'target localization missing',
        );
    }

    private function assertKnownHandles(FilterCriteria $filters): void
    {
        // Placeholder — Task 7 will implement validation.
    }
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: 4 passed.

**Step 5: Commit**

```bash
git add src/Console/TranslationPlanner.php tests/Feature/Console/TranslationPlannerTest.php
git commit -m "feat: add TranslationPlanner with entry resolution and target site expansion"
```

---

### Task 5: Planner — blueprint filter + exclusions + default target sites

**Files:**
- Modify: `src/Console/TranslationPlanner.php`
- Modify: `tests/Feature/Console/TranslationPlannerTest.php`

**Step 1: Add new failing tests**

Append to `tests/Feature/Console/TranslationPlannerTest.php`:

```php
it('filters by --blueprint handle', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles', 'default');
    $this->createTestBlueprint('articles', 'news');

    // Entry on the 'news' blueprint.
    $news = $this->createTestEntry(collection: 'articles', site: 'en', slug: 'n');
    $news->blueprint('news')->save();

    // Entry on the 'default' blueprint.
    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'd');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'blueprints' => ['news'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->entryId)->toBe($news->id());
});

it('respects config exclude_blueprints', function () {
    config(['statamic.content-translator.exclude_blueprints' => ['articles.*']]);

    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestCollection('pages', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestBlueprint('pages');
    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'a');
    $this->createTestEntry(collection: 'pages', site: 'en', slug: 'p');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->collections())->toBe(['pages']);
});

it('defaults target sites to all collection sites minus source when --to omitted', function () {
    $this->createTestCollection('articles', ['en', 'de', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => [],          // empty = default
        'sourceSite' => 'en',
    ]));

    expect($plan->count())->toBe(2);
    expect($plan->targetSites())->toBe(['de', 'fr']);
});
```

**Step 2: Run tests — verify the blueprint + exclusion tests fail**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: blueprint filter test fails; exclusion test fails; default target sites test should already pass from Task 4.

**Step 3: Implement blueprint filtering + exclusion in `resolveEntries()`**

Replace the `resolveEntries()` method body in `src/Console/TranslationPlanner.php`:

```php
/**
 * @return iterable<EntryContract>
 */
private function resolveEntries(FilterCriteria $filters): iterable
{
    // Start with explicit IDs when provided.
    if ($filters->entryIds !== []) {
        foreach ($filters->entryIds as $id) {
            $entry = EntryFacade::find($id);
            if ($entry === null) {
                continue;
            }
            if (! $this->passesBlueprintFilters($entry, $filters)) {
                continue;
            }
            yield $entry;
        }

        return;
    }

    // Otherwise query by collection (or all collections).
    $collections = $filters->collections !== []
        ? $filters->collections
        : CollectionFacade::handles()->all();

    foreach ($collections as $collectionHandle) {
        foreach (EntryFacade::query()->where('collection', $collectionHandle)->get() as $entry) {
            $root = $entry->hasOrigin() ? $entry->root() : $entry;

            if (! $this->passesBlueprintFilters($root, $filters)) {
                continue;
            }

            yield $root;
        }
    }
}

private function passesBlueprintFilters(EntryContract $entry, FilterCriteria $filters): bool
{
    $collectionHandle = $entry->collectionHandle();
    $blueprintHandle = $entry->blueprint()->handle();

    // Skip blueprints excluded via config (same as CP).
    if (BlueprintExclusions::contains($collectionHandle, $blueprintHandle)) {
        return false;
    }

    // Narrow by --blueprint=* if provided.
    if ($filters->blueprints !== [] && ! in_array($blueprintHandle, $filters->blueprints, true)) {
        return false;
    }

    return true;
}
```

Note: the query `where('collection', ...)` may return multiple localizations per entry. Deduplication by yielding the root means we might yield the same root twice for a single entry. Add a seen-ID set in `resolveEntries()` to dedupe:

```php
// In the collection-query branch, before yielding:
$seen = [];
foreach ($collections as $collectionHandle) {
    foreach (EntryFacade::query()->where('collection', $collectionHandle)->get() as $entry) {
        $root = $entry->hasOrigin() ? $entry->root() : $entry;

        if (isset($seen[$root->id()])) {
            continue;
        }
        $seen[$root->id()] = true;

        if (! $this->passesBlueprintFilters($root, $filters)) {
            continue;
        }

        yield $root;
    }
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: 7 passed.

**Step 5: Commit**

```bash
git add src/Console/TranslationPlanner.php tests/Feature/Console/TranslationPlannerTest.php
git commit -m "feat: planner applies blueprint filter and config exclusions"
```

---

### Task 6: Planner — state classification (missing/exists/stale/overwrite)

**Files:**
- Modify: `src/Console/TranslationPlanner.php`
- Modify: `tests/Feature/Console/TranslationPlannerTest.php`

**Step 1: Add failing tests**

Append to `tests/Feature/Console/TranslationPlannerTest.php`:

```php
it('skips pair when target localization already exists (safe default)', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    // Create an existing 'de' localization.
    $en->makeLocalization('de')->data([
        'title' => 'Hallo',
        'content_translator' => ['last_translated_at' => now()->toIso8601String()],
    ])->save();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->action)->toBe(PlanAction::SkipExists);
    expect($plan->processable())->toBeEmpty();
});

it('includes stale targets when --include-stale is set', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    // Target was translated a while ago, then the source was updated.
    $en->makeLocalization('de')->data([
        'title' => 'Hallo',
        'content_translator' => ['last_translated_at' => now()->subDays(7)->toIso8601String()],
    ])->save();

    // Touch the source so its lastModified() is after the target's translated_at.
    touch($en->path(), now()->timestamp);
    clearstatcache();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'includeStale' => true,
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->action)->toBe(PlanAction::Stale);
    expect($plan->processable())->toHaveCount(1);
});

it('keeps SkipExists when target is fresh even with --include-stale', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    // Target translated AFTER the source was written → not stale.
    $en->makeLocalization('de')->data([
        'title' => 'Hallo',
        'content_translator' => ['last_translated_at' => now()->addDay()->toIso8601String()],
    ])->save();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'includeStale' => true,
    ]));

    expect($plan->items[0]->action)->toBe(PlanAction::SkipExists);
});

it('marks pair Overwrite when --overwrite set and target exists', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    $en->makeLocalization('de')->data(['title' => 'Hallo'])->save();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'overwrite' => true,
    ]));

    expect($plan->items[0]->action)->toBe(PlanAction::Overwrite);
});

it('marks pair Translate when target missing regardless of overwrite flag', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $planner = app(TranslationPlanner::class);

    $withoutFlag = $planner->plan(makeCriteria(['targetSites' => ['de']]));
    $withFlag = $planner->plan(makeCriteria(['targetSites' => ['de'], 'overwrite' => true]));

    expect($withoutFlag->items[0]->action)->toBe(PlanAction::Translate);
    expect($withFlag->items[0]->action)->toBe(PlanAction::Translate);
});
```

**Step 2: Run tests — verify they fail**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: 5 new failures (all classify calls currently return Translate).

**Step 3: Implement real state classification**

Replace the `classify()` method in `src/Console/TranslationPlanner.php`:

```php
private function classify(EntryContract $entry, string $targetSite, FilterCriteria $filters): PlanItem
{
    $source = $this->resolveSourceSite($entry, $filters);
    $title = (string) ($entry->get('title') ?? $entry->id());
    $collection = $entry->collectionHandle();

    // Verify the entry's collection supports this target site.
    $collectionSites = $entry->collection()->sites()->all();
    if (! in_array($targetSite, $collectionSites, true)) {
        return $this->item($entry, $source, $targetSite, $title, $collection,
            PlanAction::SkipUnsupported, "collection does not support site {$targetSite}");
    }

    $target = $entry->in($targetSite);

    // Target missing → always translate.
    if ($target === null) {
        return $this->item($entry, $source, $targetSite, $title, $collection,
            PlanAction::Translate, 'target localization missing');
    }

    // Target exists + --overwrite → nuclear.
    if ($filters->overwrite) {
        return $this->item($entry, $source, $targetSite, $title, $collection,
            PlanAction::Overwrite, '--overwrite set');
    }

    // Target exists + --include-stale → check staleness.
    if ($filters->includeStale && $this->isStale($entry, $target)) {
        return $this->item($entry, $source, $targetSite, $title, $collection,
            PlanAction::Stale, 'source updated after last translation');
    }

    // Default: skip.
    return $this->item($entry, $source, $targetSite, $title, $collection,
        PlanAction::SkipExists, 'target localization already exists');
}

private function item(
    EntryContract $entry,
    string $source,
    string $targetSite,
    string $title,
    string $collection,
    PlanAction $action,
    string $reason,
): PlanItem {
    return new PlanItem(
        entryId: $entry->id(),
        entryTitle: $title,
        collection: $collection,
        sourceSite: $source,
        targetSite: $targetSite,
        action: $action,
        reason: $reason,
    );
}

private function isStale(EntryContract $sourceEntry, EntryContract $targetEntry): bool
{
    $meta = $targetEntry->get('content_translator');
    if (! is_array($meta) || ! isset($meta['last_translated_at'])) {
        // No record of when it was translated → treat as stale (conservative for CI).
        return true;
    }

    try {
        $lastTranslatedAt = \Carbon\Carbon::parse($meta['last_translated_at']);
    } catch (\Throwable) {
        return true;
    }

    $sourceModifiedAt = $sourceEntry->lastModified();

    return $sourceModifiedAt !== null && $sourceModifiedAt->greaterThan($lastTranslatedAt);
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: 12 passed.

**Step 5: Commit**

```bash
git add src/Console/TranslationPlanner.php tests/Feature/Console/TranslationPlannerTest.php
git commit -m "feat: planner classifies pair state (translate/stale/overwrite/skip)"
```

---

### Task 7: Planner — unknown handle errors

**Files:**
- Modify: `src/Console/TranslationPlanner.php`
- Modify: `tests/Feature/Console/TranslationPlannerTest.php`

**Step 1: Add failing tests**

Append to `tests/Feature/Console/TranslationPlannerTest.php`:

```php
it('errors on unknown collection handle', function () {
    $this->createTestCollection('articles', ['en', 'de']);

    $planner = app(TranslationPlanner::class);

    expect(fn () => $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'collections' => ['nonexistent'],
    ])))->toThrow(InvalidArgumentException::class, "Unknown collection 'nonexistent'");
});

it('errors on unknown target site handle', function () {
    $this->createTestCollection('articles', ['en', 'de']);

    $planner = app(TranslationPlanner::class);

    expect(fn () => $planner->plan(makeCriteria([
        'targetSites' => ['xx'],
    ])))->toThrow(InvalidArgumentException::class, "Unknown site 'xx'");
});

it('errors on unknown source site handle', function () {
    $this->createTestCollection('articles', ['en', 'de']);

    $planner = app(TranslationPlanner::class);

    expect(fn () => $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'sourceSite' => 'xx',
    ])))->toThrow(InvalidArgumentException::class, "Unknown site 'xx'");
});
```

**Step 2: Run tests — verify they fail**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: 3 new failures.

**Step 3: Implement `assertKnownHandles()`**

Replace the `assertKnownHandles()` method in `src/Console/TranslationPlanner.php`:

```php
private function assertKnownHandles(FilterCriteria $filters): void
{
    // Validate site handles.
    $knownSites = Site::all()->map->handle()->all();

    foreach ($filters->targetSites as $site) {
        if (! in_array($site, $knownSites, true)) {
            throw new InvalidArgumentException("Unknown site '{$site}'");
        }
    }

    if ($filters->sourceSite !== null && ! in_array($filters->sourceSite, $knownSites, true)) {
        throw new InvalidArgumentException("Unknown site '{$filters->sourceSite}'");
    }

    // Validate collection handles.
    $knownCollections = CollectionFacade::handles()->all();

    foreach ($filters->collections as $collection) {
        if (! in_array($collection, $knownCollections, true)) {
            throw new InvalidArgumentException("Unknown collection '{$collection}'");
        }
    }

    // Note: --blueprint handles aren't validated up-front because they're
    // collection-scoped and a user might apply one blueprint filter across
    // multiple collections. If no entries match, the plan is simply empty.
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslationPlannerTest
```
Expected: 15 passed.

**Step 5: Commit**

```bash
git add src/Console/TranslationPlanner.php tests/Feature/Console/TranslationPlannerTest.php
git commit -m "feat: planner validates unknown collection and site handles"
```

---

## Phase 3: Command

### Task 8: Command skeleton + --dry-run + filter requirement

**Files:**
- Create: `src/Commands/TranslateCommand.php`
- Test: `tests/Feature/Commands/TranslateCommandTest.php`

The command is auto-registered by Statamic because it lives in `src/Commands/`. No ServiceProvider edits needed.

**Step 1: Write failing tests**

Create `tests/Feature/Commands/TranslateCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

it('errors out when no filter is provided', function () {
    $this->artisan('statamic:content-translator:translate')
        ->expectsOutputToContain('at least one filter')
        ->assertExitCode(2);
});

it('errors on unknown collection handle', function () {
    $this->artisan('statamic:content-translator:translate', [
        '--collection' => ['nonexistent'],
        '--to' => ['de'],
    ])
        ->expectsOutputToContain("Unknown collection 'nonexistent'")
        ->assertExitCode(2);
});

it('prints plan summary on --dry-run and exits 0 without executing', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de'],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('translation plan')
        ->expectsOutputToContain('will translate')
        ->expectsOutputToContain('Dry run — no changes made')
        ->assertExitCode(0);
});

it('prints empty plan when no entries match filter', function () {
    $this->createTestCollection('articles', ['en', 'de']);

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de'],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('No translations to perform')
        ->assertExitCode(0);
});
```

**Step 2: Run tests — verify they fail**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: FAIL with "Command 'statamic:content-translator:translate' is not defined."

**Step 3: Implement command skeleton**

Create `src/Commands/TranslateCommand.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Commands;

use ElSchneider\ContentTranslator\Console\FilterCriteria;
use ElSchneider\ContentTranslator\Console\PlanAction;
use ElSchneider\ContentTranslator\Console\TranslationPlan;
use ElSchneider\ContentTranslator\Console\TranslationPlanner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Statamic\Console\RunsInPlease;

final class TranslateCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:content-translator:translate
                            {--to=*             : Target site handle (repeatable). Default: all sites each entry supports (minus source)}
                            {--from=            : Source site handle (default: entry origin)}
                            {--collection=*     : Filter by collection handle (repeatable)}
                            {--entry=*          : Filter by entry ID (repeatable)}
                            {--blueprint=*      : Filter by blueprint handle (repeatable)}
                            {--include-stale    : Also re-translate entries where source updated after target last_translated_at}
                            {--overwrite        : Re-translate everything regardless of existing state (nuclear option)}
                            {--generate-slug    : Slugify translated title}
                            {--dispatch-jobs    : Dispatch queue jobs instead of running synchronously}
                            {--dry-run          : Show the plan without executing}';

    protected $description = 'Translate Statamic entries across sites';

    public function handle(TranslationPlanner $planner): int
    {
        $criteria = $this->buildCriteria();

        if (! $criteria->hasAnySelectorFilter()) {
            $this->error('Refusing to run without at least one filter (--to, --collection, --entry, or --blueprint).');
            $this->line('Pass a filter to narrow the scope. Use --dry-run to preview the plan.');

            return 2;
        }

        try {
            $plan = $planner->plan($criteria);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $this->printPlan($plan, $criteria);

        if ($plan->processable() === []) {
            $this->info('No translations to perform.');

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes made.');

            return 0;
        }

        // Execution path added in subsequent tasks.
        return 0;
    }

    private function buildCriteria(): FilterCriteria
    {
        return new FilterCriteria(
            targetSites: $this->normalizeArray($this->option('to')),
            sourceSite: $this->option('from') ?: null,
            collections: $this->normalizeArray($this->option('collection')),
            entryIds: $this->normalizeArray($this->option('entry')),
            blueprints: $this->normalizeArray($this->option('blueprint')),
            includeStale: (bool) $this->option('include-stale'),
            overwrite: (bool) $this->option('overwrite'),
        );
    }

    /**
     * @param  mixed  $value
     * @return string[]
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }

        $arr = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $arr),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function printPlan(TranslationPlan $plan, FilterCriteria $criteria): void
    {
        $this->newLine();
        $this->line('<info>Content Translator — translation plan</info>');
        $this->line(str_repeat('─', 61));

        $filtersLine = sprintf(
            'Filters:       collection=%s, blueprint=%s, to=[%s]',
            $criteria->collections !== [] ? implode(',', $criteria->collections) : '*',
            $criteria->blueprints !== [] ? implode(',', $criteria->blueprints) : '*',
            $criteria->targetSites !== [] ? implode(',', $criteria->targetSites) : 'auto',
        );
        $this->line($filtersLine);
        $this->line('Source site:   '.($criteria->sourceSite ?? 'auto (entry origin)'));

        $mode = $criteria->overwrite
            ? 'overwrite (re-translate all)'
            : ($criteria->includeStale ? 'safe + stale' : 'safe (skip existing)');
        $this->line("Mode:          {$mode}");
        $this->newLine();

        $counts = $plan->countByAction();
        $this->line("Resolved:  {$plan->count()} candidate pairs");
        $this->newLine();
        $this->line('Breakdown:');
        $this->line(sprintf('  ✓ %3d  will translate          (target localization missing)', $counts[PlanAction::Translate->value] ?? 0));
        if ($criteria->includeStale) {
            $this->line(sprintf('  ↻ %3d  will re-translate       (stale)', $counts[PlanAction::Stale->value] ?? 0));
        }
        if ($criteria->overwrite) {
            $this->line(sprintf('  ↻ %3d  will overwrite          (--overwrite)', $counts[PlanAction::Overwrite->value] ?? 0));
        }
        $this->line(sprintf('  ⊘ %3d  skip — already exists   (pass --include-stale or --overwrite to process)', $counts[PlanAction::SkipExists->value] ?? 0));
        $this->line(sprintf('  ⚠ %3d  skip — unsupported site (entry\'s collection excludes target)', $counts[PlanAction::SkipUnsupported->value] ?? 0));
        $this->line(str_repeat('─', 61));

        $effective = count($plan->processable());
        $this->line("Effective work: {$effective} translations");
        $this->newLine();
    }
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: 4 passed.

**Step 5: Commit**

```bash
git add src/Commands/TranslateCommand.php tests/Feature/Commands/TranslateCommandTest.php
git commit -m "feat: add TranslateCommand with --dry-run and filter validation"
```

---

### Task 9: Command — interactive confirm + --no-interaction + TTY safety

**Files:**
- Modify: `src/Commands/TranslateCommand.php`
- Modify: `tests/Feature/Commands/TranslateCommandTest.php`

**Step 1: Add failing tests**

Append to `tests/Feature/Commands/TranslateCommandTest.php`:

```php
it('aborts gracefully when user answers no at confirm prompt', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de'],
    ])
        ->expectsConfirmation('Proceed?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertExitCode(0);
});

it('proceeds past confirm with --no-interaction', function () {
    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    // Intercept execution so this test stays focused on the confirm path.
    // For now we just expect exit 0 after the plan; sync execution is tested next.
    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de'],
        '--no-interaction' => true,
    ])
        ->assertExitCode(0);
});
```

**Step 2: Run tests — verify the confirm-abort test fails**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: confirm-abort test fails (no prompt is rendered). The `--no-interaction` test may already pass.

**Step 3: Add the confirm flow**

In `src/Commands/TranslateCommand.php`, after the `if ($this->option('dry-run'))` block and before `return 0;`, add:

```php
if (! $this->confirmExecution()) {
    $this->info('Aborted.');

    return 0;
}

// Execution path added in Task 10.
return 0;
```

Add the `confirmExecution()` method to the class:

```php
private function confirmExecution(): bool
{
    // --no-interaction bypasses the prompt (standard Symfony/Laravel semantics).
    if ($this->option('no-interaction')) {
        return true;
    }

    // Non-TTY without --no-interaction is dangerous (cron pipes, etc.).
    if (! $this->input->isInteractive()) {
        $this->error('Refusing to run non-interactively without -n / --no-interaction.');

        return false;
    }

    return $this->confirm('Proceed?', false);
}
```

Adjust the bail-out logic so the TTY-check path returns exit 2 (command-level error), not 0:

```php
// Replace the earlier block:
if (! $this->confirmExecution()) {
    // If we refused due to non-TTY + no -n, that's a command error → exit 2.
    if (! $this->option('no-interaction') && ! $this->input->isInteractive()) {
        return 2;
    }
    $this->info('Aborted.');

    return 0;
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: 6 passed.

**Step 5: Commit**

```bash
git add src/Commands/TranslateCommand.php tests/Feature/Commands/TranslateCommandTest.php
git commit -m "feat: add interactive confirm prompt with non-TTY safety"
```

---

### Task 10: Command — sync execution + summary + exit codes

**Files:**
- Modify: `src/Commands/TranslateCommand.php`
- Modify: `tests/Feature/Commands/TranslateCommandTest.php`

**Step 1: Add failing tests**

Append to `tests/Feature/Commands/TranslateCommandTest.php`:

```php
use ElSchneider\ContentTranslator\Actions\TranslateEntry;
use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use Statamic\Facades\Entry;

function bindPrefixService(string $prefix = 'DE: '): void
{
    $mock = Mockery::mock(TranslationService::class);
    $mock->shouldReceive('translate')
        ->andReturnUsing(fn (array $units) => array_map(
            fn (TranslationUnit $u) => $u->withTranslation($prefix.$u->text),
            $units,
        ));
    app()->instance(TranslationService::class, $mock);
}

it('executes sync translation and reports success summary', function () {
    bindPrefixService('DE: ');

    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de'],
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Translation summary')
        ->expectsOutputToContain('Succeeded:   1')
        ->assertExitCode(0);

    // Verify the target localization was actually created.
    $localized = Entry::find($en->id())->in('de');
    expect($localized)->not->toBeNull();
    expect($localized->get('title'))->toBe('DE: Test Entry');
});

it('reports partial failure and exits 1 when a translation throws', function () {
    $mock = Mockery::mock(TranslationService::class);
    $mock->shouldReceive('translate')
        ->andThrow(new \RuntimeException('provider exploded'));
    app()->instance(TranslationService::class, $mock);

    $this->createTestCollection('articles', ['en', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de'],
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Failed:       1')
        ->expectsOutputToContain('provider exploded')
        ->assertExitCode(1);
});
```

**Step 2: Run tests — verify they fail**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: 2 new failures (no execution happens yet; no summary printed).

**Step 3: Implement sync execution + summary**

In `src/Commands/TranslateCommand.php`:

Add imports at the top:
```php
use ElSchneider\ContentTranslator\Actions\TranslateEntry;
use ElSchneider\ContentTranslator\Console\PlanItem;
use Throwable;
```

Add the execution call in `handle()`, replacing the `// Execution path added in Task 10` placeholder:

```php
return $this->executeSync($plan->processable(), app(TranslateEntry::class));
```

Add these methods to the class:

```php
/**
 * @param  PlanItem[]  $items
 */
private function executeSync(array $items, TranslateEntry $action): int
{
    $options = $this->buildExecutionOptions();

    $progressBar = $this->output->createProgressBar(count($items));
    $progressBar->setFormat(' %current%/%max% [%bar%] %message%');
    $progressBar->setMessage('starting…');
    $progressBar->start();

    $succeeded = 0;
    $failures = [];

    foreach ($items as $item) {
        $progressBar->setMessage(sprintf(
            'translating "%s" → %s',
            $this->truncate($item->entryTitle, 40),
            $item->targetSite,
        ));

        try {
            $action->handle(
                $item->entryId,
                $item->targetSite,
                $item->sourceSite,
                $options,
            );
            $succeeded++;
        } catch (Throwable $e) {
            $failures[] = [$item, $e];
        }

        $progressBar->advance();
    }

    $progressBar->finish();
    $this->newLine(2);
    $this->printSummary($succeeded, $failures);

    return $failures === [] ? 0 : 1;
}

/**
 * @return array<string, bool>
 */
private function buildExecutionOptions(): array
{
    return [
        'overwrite' => true, // planner already filtered to processable pairs
        'generate_slug' => (bool) $this->option('generate-slug'),
    ];
}

/**
 * @param  array<int, array{0: PlanItem, 1: Throwable}>  $failures
 */
private function printSummary(int $succeeded, array $failures): void
{
    $this->line('<info>Translation summary</info>');
    $this->line(str_repeat('─', 47));
    $this->line(sprintf('✓ Succeeded:   %d', $succeeded));
    $this->line(sprintf('✗ Failed:       %d', count($failures)));
    $this->line(str_repeat('─', 47));

    if ($failures === []) {
        return;
    }

    $this->newLine();
    $this->line('<comment>Failures:</comment>');
    foreach ($failures as [$item, $e]) {
        $this->line(sprintf(
            '  %s → %s  %s: %s',
            $item->entryId,
            $item->targetSite,
            class_basename($e),
            $e->getMessage(),
        ));
    }
    $this->newLine();
    $this->comment('See storage/logs/laravel.log for full stack traces.');
}

private function truncate(string $value, int $max): string
{
    return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
}
```

Note on `'overwrite' => true` in the options: the planner already filtered to processable pairs (Translate/Stale/Overwrite), so unconditionally passing `overwrite=true` to the action is safe — we only call the action for pairs we've decided to process. Without this, `TranslateEntry` would skip targets it sees as "already existing" even when we explicitly want to re-translate (Stale/Overwrite cases).

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: 8 passed.

**Step 5: Commit**

```bash
git add src/Commands/TranslateCommand.php tests/Feature/Commands/TranslateCommandTest.php
git commit -m "feat: add sync execution with progress bar, summary, and exit codes"
```

---

### Task 11: Command — async dispatch (--dispatch-jobs)

**Files:**
- Modify: `src/Commands/TranslateCommand.php`
- Modify: `tests/Feature/Commands/TranslateCommandTest.php`

**Step 1: Add failing tests**

Append to `tests/Feature/Commands/TranslateCommandTest.php`:

```php
use ElSchneider\ContentTranslator\Jobs\TranslateEntryJob;
use Illuminate\Support\Facades\Queue;

it('dispatches a job per processable pair when --dispatch-jobs is set', function () {
    Queue::fake();

    $this->createTestCollection('articles', ['en', 'de', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de', 'fr'],
        '--dispatch-jobs' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Dispatched 2 job')
        ->assertExitCode(0);

    Queue::assertPushed(TranslateEntryJob::class, 2);
});

it('dispatches zero jobs when plan is empty', function () {
    Queue::fake();

    $this->createTestCollection('articles', ['en', 'de']);

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['de'],
        '--dispatch-jobs' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('No translations to perform')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});
```

**Step 2: Run tests — verify they fail**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: new failures (sync path runs, no jobs dispatched).

**Step 3: Implement async dispatch**

In `src/Commands/TranslateCommand.php`:

Add the import:
```php
use ElSchneider\ContentTranslator\Jobs\TranslateEntryJob;
```

Replace the single `return $this->executeSync(...)` call with a branch:

```php
if ($this->option('dispatch-jobs')) {
    return $this->dispatchJobs($plan->processable());
}

return $this->executeSync($plan->processable(), app(TranslateEntry::class));
```

Add the dispatcher method:

```php
/**
 * @param  PlanItem[]  $items
 */
private function dispatchJobs(array $items): int
{
    $options = $this->buildExecutionOptions();
    $count = 0;

    foreach ($items as $item) {
        TranslateEntryJob::dispatch(
            $item->entryId,
            $item->targetSite,
            $item->sourceSite,
            $options,
        );
        $count++;
    }

    $this->info(sprintf('Dispatched %d job%s to the queue.', $count, $count === 1 ? '' : 's'));
    $this->comment('Track status: GET /cp/content-translator/status or run `php artisan queue:work`.');

    return 0;
}
```

**Step 4: Run tests — verify they pass**

```bash
./vendor/bin/pest --filter=TranslateCommandTest
```
Expected: 10 passed.

**Step 5: Commit**

```bash
git add src/Commands/TranslateCommand.php tests/Feature/Commands/TranslateCommandTest.php
git commit -m "feat: add --dispatch-jobs for async queue-based translation"
```

---

## Phase 4: Documentation & Verification

### Task 12: README usage section + CHANGELOG entry + smoke test

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Step 1: Add to README**

Open `README.md` and add this section (place it after any CP usage section, before "Configuration"):

```markdown
## CLI Usage

The addon ships with a flexible artisan command for bulk and automated translation:

\`\`\`bash
php please statamic:content-translator:translate [options]
\`\`\`

**Requires at least one filter** (`--to`, `--collection`, `--entry`, or `--blueprint`).

### Common examples

Preview what would be translated for a collection (safe, no changes):

\`\`\`bash
php please statamic:content-translator:translate --collection=pages --to=de --dry-run
\`\`\`

Translate all missing `pages` entries into German and French, async:

\`\`\`bash
php please statamic:content-translator:translate --collection=pages --to=de --to=fr --dispatch-jobs -n
\`\`\`

Re-translate stale entries (source updated after last translation) for CI/cron:

\`\`\`bash
php please statamic:content-translator:translate --collection=pages --include-stale --dispatch-jobs -n
\`\`\`

Translate one specific entry to every site its collection supports:

\`\`\`bash
php please statamic:content-translator:translate --entry=abc-123
\`\`\`

### Options

| Option | Description |
|---|---|
| `--to=*` | Target site handle (repeatable). Default: all sites each entry supports. |
| `--from=` | Source site handle. Default: entry's origin. |
| `--collection=*` | Filter by collection handle (repeatable). |
| `--entry=*` | Filter by entry ID (repeatable). |
| `--blueprint=*` | Filter by blueprint handle (repeatable). |
| `--include-stale` | Also re-translate entries where source was updated after target's `last_translated_at`. |
| `--overwrite` | Re-translate everything regardless of existing state. |
| `--generate-slug` | Slugify translated title. |
| `--dispatch-jobs` | Dispatch queue jobs instead of running synchronously. |
| `--dry-run` | Print the plan without executing. Exits 0. |
| `-n` / `--no-interaction` | Skip the confirm prompt. Required in CI. |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Success, dry-run, empty plan, or user declined. |
| `1` | Partial failure — some translations failed. |
| `2` | Command-level error (bad args, unknown handle, non-TTY without `-n`). |
```

**Step 2: Add to CHANGELOG**

Open `CHANGELOG.md` and add at the top of the unreleased section:

```markdown
### Added

- **Artisan command `statamic:content-translator:translate`** — flexible CLI tool for bulk, CI-driven, and surgical translation. Supports filtering by collection, entry ID, blueprint, and target sites; dry-run preview; interactive confirmation with `-n` bypass; sync with progress bar or async queue dispatch via `--dispatch-jobs`; `--include-stale` for CI/cron, `--overwrite` nuclear option.
```

**Step 3: Run the full test suite**

```bash
./vendor/bin/pest
```
Expected: all tests green.

**Step 4: Run Pint**

```bash
./vendor/bin/pint
```

**Step 5: Manual smoke test against the v5 sandbox**

Detect sandbox layout:
```bash
ls ../statamic-content-translator-test/artisan 2>/dev/null && echo "sibling" || echo "nested"
```

If sibling, run against it:
```bash
php ../statamic-content-translator-test/artisan statamic:content-translator:translate --collection=pages --to=de --dry-run
```

Verify the output matches the expected plan format: header, filter line, source/mode lines, breakdown, "Dry run — no changes made."

**Step 6: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: document statamic:content-translator:translate artisan command"
```

**Step 7: Final verification**

```bash
./vendor/bin/pest
./vendor/bin/pint --test
```
Both should be green.

---

## Completion Checklist

- [ ] All tests pass (`./vendor/bin/pest`)
- [ ] Pint is clean (`./vendor/bin/pint --test`)
- [ ] Manual smoke test against sandbox verified
- [ ] README has CLI usage section
- [ ] CHANGELOG entry added
- [ ] All 12 tasks committed on branch `feat/artisan-translate-command`

## Out of Scope

- `--json` machine-readable output (add in follow-up if needed)
- Writing failure details to a dedicated log file (`storage/logs/content-translator-{ts}.log`) — current implementation relies on the existing `TranslationLogger` + Laravel log. Add if power users request it.
- Status-polling await mode for async dispatch (command exits after dispatch; users poll via the existing `/cp/content-translator/status` endpoint).
- Support for translating terms, globals, assets, or nav — entry-only, matches the CP.
