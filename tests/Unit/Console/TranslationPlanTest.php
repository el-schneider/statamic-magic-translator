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
