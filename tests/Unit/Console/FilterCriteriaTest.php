<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Console\FilterCriteria;

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
