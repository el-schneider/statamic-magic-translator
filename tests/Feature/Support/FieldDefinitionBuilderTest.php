<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Data\TranslationFormat;
use ElSchneider\MagicTranslator\Exceptions\SourceContentInvalidException;
use ElSchneider\MagicTranslator\Exceptions\TranslationConfigException;
use ElSchneider\MagicTranslator\Extraction\ContentExtractor;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use Statamic\Facades\Fieldset;

beforeEach(function () {
    Fieldset::make('callout_fields')->setContents([
        'title' => 'Callout Fields',
        'fields' => [
            ['handle' => 'callout_label', 'field' => ['type' => 'text', 'localizable' => true, 'display' => 'Label']],
            ['handle' => 'callout_text', 'field' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Text']],
        ],
    ])->save();
});

function extractFromBardBlueprint(array $setFields, array $setValues): array
{
    $blueprint = test()->createTestBlueprint('articles', 'with_imports', [
        [
            'handle' => 'content',
            'field' => [
                'type' => 'bard',
                'localizable' => true,
                'sets' => [
                    'callout' => ['display' => 'Callout', 'fields' => $setFields],
                ],
            ],
        ],
    ]);

    $fieldDefs = FieldDefinitionBuilder::fromBlueprint($blueprint);

    $data = [
        'content' => [
            ['type' => 'set', 'attrs' => ['values' => array_merge(['type' => 'callout'], $setValues)]],
        ],
    ];

    return (new ContentExtractor)->extract($data, $fieldDefs);
}

it('extracts content from bard set fields imported via a fieldset', function () {
    $units = extractFromBardBlueprint(
        [['import' => 'callout_fields']],
        ['callout_label' => 'Heads up', 'callout_text' => 'Something important'],
    );

    $paths = collect($units)->pluck('path');

    expect($paths)->toContain('content.0.attrs.values.callout_label');
    expect($paths)->toContain('content.0.attrs.values.callout_text');
    expect(collect($units)->firstWhere('path', 'content.0.attrs.values.callout_label')->text)
        ->toBe('Heads up');
});

it('extracts imported fields under their prefixed handles', function () {
    $units = extractFromBardBlueprint(
        [['import' => 'callout_fields', 'prefix' => 'hero_']],
        ['hero_callout_label' => 'Heads up'],
    );

    $paths = collect($units)->pluck('path');

    expect($paths)->toContain('content.0.attrs.values.hero_callout_label');
});

it('extracts fields referenced via fieldset.handle string references', function () {
    $units = extractFromBardBlueprint(
        [['handle' => 'tagline', 'field' => 'callout_fields.callout_label']],
        ['tagline' => 'Heads up'],
    );

    $paths = collect($units)->pluck('path');

    expect($paths)->toContain('content.0.attrs.values.tagline');
});

// ── Custom fieldtypes ─────────────────────────────────────────────────────────

it('skips a custom fieldtype that is not opted in', function () {
    $blueprint = test()->createTestBlueprint('articles', 'custom_fieldtype', [
        ['handle' => 'seo_title', 'field' => ['type' => 'addon_seo_title', 'localizable' => true]],
    ]);

    $units = (new ContentExtractor)->extract(
        ['seo_title' => 'Heads up'],
        FieldDefinitionBuilder::fromBlueprint($blueprint),
    );

    expect($units)->toBe([]);
});

it('extracts a custom fieldtype declared as plain', function () {
    config()->set('statamic.magic-translator.custom_fieldtypes', ['addon_seo_title' => 'plain']);

    $blueprint = test()->createTestBlueprint('articles', 'custom_fieldtype', [
        ['handle' => 'seo_title', 'field' => ['type' => 'addon_seo_title', 'localizable' => true]],
    ]);

    $units = (new ContentExtractor)->extract(
        ['seo_title' => 'Heads up'],
        FieldDefinitionBuilder::fromBlueprint($blueprint),
    );

    expect($units)->toHaveCount(1)
        ->and($units[0]->path)->toBe('seo_title')
        ->and($units[0]->text)->toBe('Heads up');
});

it('resolves a custom fieldtype nested inside a set', function () {
    config()->set('statamic.magic-translator.custom_fieldtypes', ['addon_seo_title' => 'plain']);

    $units = extractFromBardBlueprint(
        [['handle' => 'seo_title', 'field' => ['type' => 'addon_seo_title']]],
        ['seo_title' => 'Heads up'],
    );

    expect(collect($units)->pluck('path'))->toContain('content.0.attrs.values.seo_title');
});

it('applies the declared content format', function () {
    config()->set('statamic.magic-translator.custom_fieldtypes', ['addon_body' => 'markdown']);

    $blueprint = test()->createTestBlueprint('articles', 'custom_fieldtype', [
        ['handle' => 'body', 'field' => ['type' => 'addon_body', 'localizable' => true]],
    ]);

    $units = (new ContentExtractor)->extract(
        ['body' => '**bold**'],
        FieldDefinitionBuilder::fromBlueprint($blueprint),
    );

    expect($units[0]->format)->toBe(TranslationFormat::Markdown);
});

it('rejects a container type as a declared format', function () {
    config()->set('statamic.magic-translator.custom_fieldtypes', ['addon_seo_title' => 'bard']);

    $blueprint = test()->createTestBlueprint('articles', 'custom_fieldtype', [
        ['handle' => 'seo_title', 'field' => ['type' => 'addon_seo_title', 'localizable' => true]],
    ]);

    expect(fn () => FieldDefinitionBuilder::fromBlueprint($blueprint))
        ->toThrow(TranslationConfigException::class, 'custom_fieldtypes[addon_seo_title] is [bard], expected plain or markdown.');
});

it('rejects a non-string declared format', function () {
    config()->set('statamic.magic-translator.custom_fieldtypes', ['addon_seo_title' => ['plain']]);

    $blueprint = test()->createTestBlueprint('articles', 'custom_fieldtype', [
        ['handle' => 'seo_title', 'field' => ['type' => 'addon_seo_title', 'localizable' => true]],
    ]);

    expect(fn () => FieldDefinitionBuilder::fromBlueprint($blueprint))
        ->toThrow(TranslationConfigException::class, 'custom_fieldtypes[addon_seo_title] is [array], expected plain or markdown.');
});

it('rejects a built-in fieldtype being redeclared', function () {
    config()->set('statamic.magic-translator.custom_fieldtypes', ['bard' => 'plain']);

    $blueprint = test()->createTestBlueprint('articles', 'custom_fieldtype', [
        ['handle' => 'body', 'field' => ['type' => 'bard', 'localizable' => true]],
    ]);

    expect(fn () => FieldDefinitionBuilder::fromBlueprint($blueprint))
        ->toThrow(TranslationConfigException::class, 'custom_fieldtypes[bard] is a built-in fieldtype and cannot be redeclared.');
});

it('refuses to flatten a custom fieldtype that turns out to hold an array', function () {
    config()->set('statamic.magic-translator.custom_fieldtypes', ['addon_seo' => 'plain']);

    $blueprint = test()->createTestBlueprint('articles', 'custom_fieldtype', [
        ['handle' => 'seo', 'field' => ['type' => 'addon_seo', 'localizable' => true]],
    ]);

    $extract = fn () => (new ContentExtractor)->extract(
        ['seo' => ['title' => 'Heads up', 'description' => 'More']],
        FieldDefinitionBuilder::fromBlueprint($blueprint),
    );

    expect($extract)->toThrow(SourceContentInvalidException::class, 'Field [seo] is typed [text] but holds array.');
});
