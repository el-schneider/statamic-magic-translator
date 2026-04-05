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
