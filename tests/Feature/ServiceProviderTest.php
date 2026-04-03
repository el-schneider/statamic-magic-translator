<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Extraction\BardSerializer;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;
use ElSchneider\ContentTranslator\Reassembly\BardParser;
use ElSchneider\ContentTranslator\Reassembly\ContentReassembler;
use ElSchneider\ContentTranslator\Services\TranslationServiceFactory;
use Statamic\Events\EntryBlueprintFound;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

// ── Config ────────────────────────────────────────────────────────────────────

it('merges the addon config under the content-translator key', function () {
    expect(config('content-translator'))->toBeArray();
    expect(config('content-translator.service'))->toBe('prism');
    expect(config('content-translator.collections'))->toBe([]);
    expect(config('content-translator.exclude_blueprints'))->toBe([]);
});

// ── Service container bindings ────────────────────────────────────────────────

it('resolves TranslationService from the container', function () {
    expect(app(TranslationService::class))->toBeInstanceOf(TranslationService::class);
});

it('returns the same TranslationService instance (singleton)', function () {
    $a = app(TranslationService::class);
    $b = app(TranslationService::class);

    expect($a)->toBe($b);
});

it('resolves TranslationServiceFactory as singleton', function () {
    expect(app(TranslationServiceFactory::class))->toBeInstanceOf(TranslationServiceFactory::class);
    expect(app(TranslationServiceFactory::class))->toBe(app(TranslationServiceFactory::class));
});

it('resolves BardSerializer as singleton', function () {
    expect(app(BardSerializer::class))->toBeInstanceOf(BardSerializer::class);
    expect(app(BardSerializer::class))->toBe(app(BardSerializer::class));
});

it('resolves BardParser as singleton', function () {
    expect(app(BardParser::class))->toBeInstanceOf(BardParser::class);
    expect(app(BardParser::class))->toBe(app(BardParser::class));
});

it('resolves ContentExtractor as singleton', function () {
    expect(app(ContentExtractor::class))->toBeInstanceOf(ContentExtractor::class);
    expect(app(ContentExtractor::class))->toBe(app(ContentExtractor::class));
});

it('resolves ContentReassembler as singleton', function () {
    expect(app(ContentReassembler::class))->toBeInstanceOf(ContentReassembler::class);
    expect(app(ContentReassembler::class))->toBe(app(ContentReassembler::class));
});

// ── Blueprint injection ───────────────────────────────────────────────────────

it('injects content_translator field into blueprint for a configured collection', function () {
    // Configure the collection.
    config(['content-translator.collections' => ['articles']]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    // Fire the event as Statamic does when resolving an entry's blueprint.
    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeTrue();
});

it('injects the field with the correct configuration', function () {
    config(['content-translator.collections' => ['articles']]);

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

it('does not inject into blueprints for unconfigured collections', function () {
    // 'articles' is NOT in the configured collections.
    config(['content-translator.collections' => ['news']]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeFalse();
});

it('excludes blueprints listed in exclude_blueprints config', function () {
    config([
        'content-translator.collections' => ['articles'],
        'content-translator.exclude_blueprints' => ['articles.default'],
    ]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeFalse();
});

it('injects into a non-excluded blueprint within a configured collection', function () {
    // Only 'articles.special' is excluded — 'articles.default' should still be injected.
    config([
        'content-translator.collections' => ['articles'],
        'content-translator.exclude_blueprints' => ['articles.special'],
    ]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $event = new EntryBlueprintFound($blueprint, $entry);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeTrue();
});

it('skips injection when the event has no entry', function () {
    config(['content-translator.collections' => ['articles']]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $blueprint = test()->createTestBlueprint('articles', 'default');

    // Fire event without an entry (null).
    $event = new EntryBlueprintFound($blueprint, null);
    event($event);

    expect($event->blueprint->hasField('content_translator'))->toBeFalse();
});

// ── Views ─────────────────────────────────────────────────────────────────────

it('loads the system prompt view under the content-translator namespace', function () {
    $rendered = view('content-translator::prompts.system', [
        'sourceLocale' => 'en',
        'targetLocale' => 'fr',
        'sourceLocaleName' => 'English',
        'targetLocaleName' => 'French',
        'hasHtmlUnits' => false,
        'hasMarkdownUnits' => false,
    ])->render();

    expect($rendered)->toBeString()->not->toBeEmpty();
});

it('loads the user prompt view under the content-translator namespace', function () {
    $rendered = view('content-translator::prompts.user', [
        'sourceLocale' => 'en',
        'targetLocale' => 'fr',
        'sourceLocaleName' => 'English',
        'targetLocaleName' => 'French',
        'hasHtmlUnits' => false,
        'hasMarkdownUnits' => false,
        'units' => [],
    ])->render();

    expect($rendered)->toBeString()->not->toBeEmpty();
});
