<?php

declare(strict_types=1);

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
