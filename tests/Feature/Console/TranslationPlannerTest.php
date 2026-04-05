<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Console\FilterCriteria;
use ElSchneider\ContentTranslator\Console\PlanAction;
use ElSchneider\ContentTranslator\Console\TranslationPlanner;
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
    $this->createTestEntry(collection: 'articles', site: 'en', slug: 'b');

    $planner = app(TranslationPlanner::class);
    $plan = $planner->plan(makeCriteria([
        'targetSites' => ['de'],
        'entryIds' => [$e1->id()],
    ]));

    expect($plan->count())->toBe(1);
    expect($plan->items[0]->entryId)->toBe($e1->id());
});
