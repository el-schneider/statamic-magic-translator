<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Console\FilterCriteria;
use ElSchneider\MagicTranslator\Console\PlanAction;
use ElSchneider\MagicTranslator\Console\TranslationPlanner;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

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
    $this->createTestCollection('articles', ['en', 'fr']);

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria(['targetSites' => ['fr']]));

    expect($plan->isEmpty())->toBeTrue();
});

it('plans one pair per entry × target site', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'sourceSite' => 'en',
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->targetSites())->toBe(['fr']);

    foreach ($plan->items as $item) {
        expect($item->entryId)->toBe($entry->id());
        expect($item->action)->toBe(PlanAction::Translate);
        expect($item->sourceSite)->toBe('en');
    }
});

it('filters by --collection', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestCollection('pages', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestBlueprint('pages');
    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'article-1');
    $this->createTestEntry(collection: 'pages', site: 'en', slug: 'page-1');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'collections' => ['articles'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->collections())->toBe(['articles']);
});

it('filters by --entry IDs', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $e1 = $this->createTestEntry(collection: 'articles', site: 'en', slug: 'a');
    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'b');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'entryIds' => [$e1->id()],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->entryId)->toBe($e1->id());
});

it('filters by --blueprint handle', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default');
    $this->createTestBlueprint('articles', 'news');

    $news = $this->createTestEntry(collection: 'articles', site: 'en', slug: 'n');
    $news->blueprint('news')->save();

    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'd');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'blueprints' => ['news'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->entryId)->toBe($news->id());
});

it('respects config exclude_blueprints', function () {
    config(['statamic.magic-translator.exclude_blueprints' => ['articles.*']]);

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestCollection('pages', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestBlueprint('pages');
    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'a');
    $this->createTestEntry(collection: 'pages', site: 'en', slug: 'p');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->collections())->toBe(['pages']);
});

it('defaults target sites to all collection sites minus source when --to omitted', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => [],
        'sourceSite' => 'en',
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->targetSites())->toBe(['fr']);
});

it('skips pair when target localization already exists (safe default)', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    $en->makeLocalization('fr')->data([
        'title' => 'Hallo',
        'magic_translator' => ['last_translated_at' => now()->toIso8601String()],
    ])->save();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->action)->toBe(PlanAction::SkipExists);
    expect($plan->processable())->toBeEmpty();
});

it('includes stale targets when --include-stale is set', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    $en->makeLocalization('fr')->data([
        'title' => 'Hallo',
        'magic_translator' => ['last_translated_at' => now()->subDays(7)->toIso8601String()],
    ])->save();

    touch($en->path(), now()->timestamp);
    clearstatcache();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'includeStale' => true,
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->action)->toBe(PlanAction::Stale);
    expect($plan->processable())->toHaveCount(1);
});

it('keeps SkipExists when target is fresh even with --include-stale', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    $en->makeLocalization('fr')->data([
        'title' => 'Hallo',
        'magic_translator' => ['last_translated_at' => now()->addDay()->toIso8601String()],
    ])->save();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'includeStale' => true,
    ]));

    expect($plan->items[0]->action)->toBe(PlanAction::SkipExists);
});

it('marks pair Overwrite when --overwrite set and target exists', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    $en->makeLocalization('fr')->data(['title' => 'Hallo'])->save();

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'overwrite' => true,
    ]));

    expect($plan->items[0]->action)->toBe(PlanAction::Overwrite);
});

it('marks pair Translate when target missing regardless of overwrite flag', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $planner = app(TranslationPlanner::class);

    $withoutFlag = $planner->plan(makeCriteria(['targetSites' => ['fr']]));
    $withFlag = $planner->plan(makeCriteria(['targetSites' => ['fr'], 'overwrite' => true]));

    expect($withoutFlag->items[0]->action)->toBe(PlanAction::Translate);
    expect($withFlag->items[0]->action)->toBe(PlanAction::Translate);
});

it('errors on unknown collection handle', function () {
    $this->createTestCollection('articles', ['en', 'fr']);

    $planner = app(TranslationPlanner::class);

    expect(fn () => $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'collections' => ['nonexistent'],
    ])))->toThrow(InvalidArgumentException::class, "Unknown collection 'nonexistent'");
});

it('errors on unknown target site handle', function () {
    $this->createTestCollection('articles', ['en', 'fr']);

    $planner = app(TranslationPlanner::class);

    expect(fn () => $planner->plan(makeCriteria([
        'targetSites' => ['xx'],
    ])))->toThrow(InvalidArgumentException::class, "Unknown site 'xx'");
});

it('errors on unknown source site handle', function () {
    $this->createTestCollection('articles', ['en', 'fr']);

    $planner = app(TranslationPlanner::class);

    expect(fn () => $planner->plan(makeCriteria([
        'targetSites' => ['fr'],
        'sourceSite' => 'xx',
    ])))->toThrow(InvalidArgumentException::class, "Unknown site 'xx'");
});
