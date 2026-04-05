<?php

declare(strict_types=1);

use Statamic\Events\EntryBlueprintFound;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

// ── Blueprint injection ───────────────────────────────────────────────────────

it('injects content_translator field into entry blueprints by default', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    // Fire the event as Statamic does when resolving an entry's blueprint.
    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeTrue();
});

it('injects the field with the correct configuration', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    $field = $event->blueprint->field('content_translator');

    expect($field)->not->toBeNull();
    expect($field->type())->toBe('content_translator');
    expect($field->get('visibility'))->toBe('computed');
    expect($field->get('localizable'))->toBeTrue();
    expect($field->get('display'))->toBe('Content Translator');
    expect($field->get('listable'))->toBe('hidden');
});

it('excludes exact blueprints listed in exclude_blueprints config', function () {
    config([
        'statamic.content-translator.exclude_blueprints' => ['articles.default'],
    ]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeFalse();
});

it('excludes wildcard blueprint patterns in exclude_blueprints config', function () {
    config([
        'statamic.content-translator.exclude_blueprints' => ['articles.*'],
    ]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeFalse();
});

it('injects into a non-excluded blueprint', function () {
    config([
        'statamic.content-translator.exclude_blueprints' => ['articles.special'],
    ]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeTrue();
});

it('skips injection when the event has no entry', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');

    // Fire event without an entry (null).
    $event = new EntryBlueprintFound($blueprint, null);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeFalse();
});
