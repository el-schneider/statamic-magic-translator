<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Console\PlanAction;
use ElSchneider\MagicTranslator\Console\PlanItem;

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
