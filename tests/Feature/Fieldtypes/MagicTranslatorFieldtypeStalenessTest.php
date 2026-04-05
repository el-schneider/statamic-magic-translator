<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Fieldtypes\MagicTranslatorFieldtype;
use ElSchneider\MagicTranslator\Support\ContentFingerprint;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use Statamic\Fields\Field;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

function preloadForEntry($entry): array
{
    $fieldtype = new MagicTranslatorFieldtype;
    $field = (new Field('magic_translator', ['type' => 'magic_translator']))->setParent($entry);
    $fieldtype->setField($field);

    return $fieldtype->preload();
}

it('marks localization stale when source translatable content changes', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
        'publish_date' => ['type' => 'date', 'localizable' => true],
    ]);

    $entry = $this->createTestEntry(collection: 'articles', site: 'en', data: [
        'title' => 'Hello',
        'publish_date' => '2026-01-01',
    ]);

    $fieldDefs = FieldDefinitionBuilder::fromBlueprint($entry->blueprint());
    $sourceHash = ContentFingerprint::compute($entry->data()->all(), $fieldDefs);

    $entry->makeLocalization('fr')->data([
        'title' => 'Bonjour',
        'magic_translator' => [
            'last_translated_at' => now()->toIso8601String(),
            'source_content_hash' => $sourceHash,
        ],
    ])->save();

    $entry->set('title', 'Hello updated')->save();

    $result = preloadForEntry($entry);
    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');

    expect($frSite['is_stale'])->toBeTrue();
});

it('keeps localization fresh when source edits do not affect extractable units', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
        'publish_date' => ['type' => 'date', 'localizable' => true],
    ]);

    $entry = $this->createTestEntry(collection: 'articles', site: 'en', data: [
        'title' => 'Hello',
        'publish_date' => '2026-01-01',
    ]);

    $fieldDefs = FieldDefinitionBuilder::fromBlueprint($entry->blueprint());
    $sourceHash = ContentFingerprint::compute($entry->data()->all(), $fieldDefs);

    $entry->makeLocalization('fr')->data([
        'title' => 'Bonjour',
        'magic_translator' => [
            'last_translated_at' => now()->toIso8601String(),
            'source_content_hash' => $sourceHash,
        ],
    ])->save();

    $entry->set('publish_date', '2026-02-01')->save();

    $result = preloadForEntry($entry);
    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');

    expect($frSite['is_stale'])->toBeFalse();
});

it('falls back to timestamp staleness when only last_translated_at exists', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');

    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $entry->makeLocalization('fr')->data([
        'title' => 'Bonjour',
        'magic_translator' => [
            'last_translated_at' => now()->subHour()->toIso8601String(),
        ],
    ])->save();

    $entry->set('title', 'Updated title')->save();

    $result = preloadForEntry($entry);
    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');

    expect($frSite['is_stale'])->toBeTrue();
});
