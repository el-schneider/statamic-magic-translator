<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Actions\TranslateEntry;
use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Data\TranslationUnit;
use ElSchneider\MagicTranslator\Support\ContentFingerprint;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

function sourceHashFor($entry): string
{
    $source = $entry->hasOrigin() ? $entry->root() : $entry;
    $fieldDefs = FieldDefinitionBuilder::fromBlueprint($source->blueprint());

    return ContentFingerprint::compute($source->data()->all(), $fieldDefs);
}

/**
 * @return array{origin: Statamic\Entries\Entry, localization: Statamic\Entries\Entry}
 */
function setUpLocalizedEntry(?array $meta = null): array
{
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
        'publish_date' => ['type' => 'date', 'localizable' => true],
    ]);

    $origin = test()->createTestEntry(collection: 'articles', site: 'en', data: [
        'title' => 'Hello',
        'publish_date' => '2026-01-01',
    ]);

    $localization = $origin->makeLocalization('fr');

    $data = [
        'title' => 'Bonjour',
        'publish_date' => '2026-01-01',
    ];

    if ($meta !== null) {
        $data['magic_translator'] = $meta;
    }

    Blink::put("magic-translator:translating:{$localization->id()}", true);

    try {
        $localization->data($data);
        $localization->save();
    } finally {
        Blink::forget("magic-translator:translating:{$localization->id()}");
    }

    $origin = Entry::find($origin->id());
    $localization = $origin->in('fr');

    return ['origin' => $origin, 'localization' => $localization];
}

it('adds metadata when a localization without magic_translator is saved with translatable changes', function () {
    ['origin' => $origin, 'localization' => $localization] = setUpLocalizedEntry();

    expect($localization->get('magic_translator'))->toBeNull();

    $draft = clone $localization;
    $draft->set('title', 'Bonjour manuel');
    $draft->save();

    $freshLocalization = Entry::find($origin->id())->in('fr');
    $meta = $freshLocalization->get('magic_translator');

    expect($meta)->toBeArray();
    expect($meta)->toHaveKey('last_translated_at');
    expect($meta)->toHaveKey('source_content_hash');
    expect($meta['source_content_hash'])->toBe(sourceHashFor(Entry::find($origin->id())));
});

it('keeps metadata unchanged when only non-translatable fields change on a localization', function () {
    $initialMeta = [
        'last_translated_at' => '2026-04-05T10:00:00+00:00',
        'source_content_hash' => 'v1:sha256:seeded-hash',
        'custom' => 'keep-me',
    ];

    ['origin' => $origin, 'localization' => $localization] = setUpLocalizedEntry($initialMeta);

    $draft = clone $localization;
    $draft->set('publish_date', '2026-02-01');
    $draft->save();

    $freshLocalization = Entry::find($origin->id())->in('fr');

    expect($freshLocalization->get('magic_translator'))->toBe($initialMeta);
});

it('refreshes metadata when translatable fields change on a localization', function () {
    ['origin' => $origin, 'localization' => $localization] = setUpLocalizedEntry();

    $initialMeta = [
        'last_translated_at' => '2026-04-05T10:00:00+00:00',
        'source_content_hash' => sourceHashFor(Entry::find($origin->id())),
    ];

    Blink::put("magic-translator:translating:{$localization->id()}", true);

    try {
        $draft = clone $localization;
        $draft->set('magic_translator', $initialMeta);
        $draft->save();
    } finally {
        Blink::forget("magic-translator:translating:{$localization->id()}");
    }

    $origin = Entry::find($origin->id());
    $origin->set('title', 'Hello updated');
    $origin->save();

    $expectedSourceHash = sourceHashFor(Entry::find($origin->id()));

    $localization = Entry::find($origin->id())->in('fr');
    $draft = clone $localization;
    $draft->set('title', 'Bonjour édité manuellement');
    $draft->save();

    $freshLocalization = Entry::find($origin->id())->in('fr');
    $meta = $freshLocalization->get('magic_translator');

    expect($meta['source_content_hash'])->toBe($expectedSourceHash);
    expect($meta['last_translated_at'])->not->toBe('2026-04-05T10:00:00+00:00');
});

it('uses a blink recursion guard during TranslateEntry saves and clears it afterwards', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $origin = test()->createTestEntry(collection: 'articles', site: 'en', data: [
        'title' => 'Hello world',
    ]);

    $mock = Mockery::mock(TranslationService::class);
    $mock->shouldReceive('translate')->once()->andReturnUsing(fn (array $units): array => array_map(
        fn (TranslationUnit $unit) => $unit->withTranslation('FR: '.$unit->text),
        $units,
    ));
    app()->instance(TranslationService::class, $mock);

    $guardObservedDuringLocalizationSave = false;

    Event::listen(EntrySaving::class, function (EntrySaving $event) use (&$guardObservedDuringLocalizationSave): void {
        $entry = $event->entry;

        if ($entry->isRoot()) {
            return;
        }

        if (Blink::has("magic-translator:translating:{$entry->id()}")) {
            $guardObservedDuringLocalizationSave = true;
        }
    });

    app(TranslateEntry::class)->handle($origin->id(), 'fr');

    $origin = Entry::find($origin->id());
    $localization = $origin->in('fr');
    $meta = $localization->get('magic_translator');

    expect($meta['source_content_hash'])->toBe(sourceHashFor($origin));
    expect($meta['last_translated_at'])->toBeString()->not->toBeEmpty();
    expect($guardObservedDuringLocalizationSave)->toBeTrue();
    expect(Blink::has("magic-translator:translating:{$localization->id()}"))->toBeFalse();
});

it('does not refresh metadata when saving the origin entry', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $origin = test()->createTestEntry(collection: 'articles', site: 'en', data: [
        'title' => 'Hello',
    ]);

    expect($origin->get('magic_translator'))->toBeNull();

    $origin->set('title', 'Hello edited');
    $origin->save();

    $freshOrigin = Entry::find($origin->id());

    expect($freshOrigin->get('magic_translator'))->toBeNull();
});
