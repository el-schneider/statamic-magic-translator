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
