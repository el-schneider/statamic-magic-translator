<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Fieldtypes\ContentTranslatorFieldtype;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Fields\Field;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

it('preserves content_translator metadata when saving localized entry with limited _localized fields', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default', [
        ['handle' => 'title', 'field' => ['type' => 'text', 'localizable' => true]],
        ['handle' => 'body', 'field' => ['type' => 'textarea', 'localizable' => true]],
    ]);

    $origin = $this->createTestEntry(
        collection: 'articles',
        site: 'en',
        data: ['title' => 'English Title', 'body' => 'English Body']
    );

    $fr = $origin->makeLocalization('fr');
    $fr->data([
        'title' => 'French Title',
        'body' => 'French Body',
        'content_translator' => ['last_translated_at' => '2024-06-15T10:30:00+00:00'],
    ]);
    $fr->save();

    $fr = Entry::find($fr->id());

    expect($fr->get('content_translator'))->toBe(['last_translated_at' => '2024-06-15T10:30:00+00:00']);

    // Seed Blink fallback metadata.
    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($fr);
    $fieldtype->setField($field);
    $fieldtype->preload();

    expect(Blink::get("content-translator:meta:{$fr->id()}"))
        ->toBe(['last_translated_at' => '2024-06-15T10:30:00+00:00']);

    // Simulate localized save where computed fields are absent from values.
    $localizedFieldsFromRequest = ['title', 'body'];
    $values = collect($fr->data()->all());
    $newData = $values->only($localizedFieldsFromRequest);

    $fr->data($newData);
    $fr->save();

    $fr = Entry::find($fr->id());

    expect($fr->get('content_translator'))->toBe(['last_translated_at' => '2024-06-15T10:30:00+00:00']);
});
