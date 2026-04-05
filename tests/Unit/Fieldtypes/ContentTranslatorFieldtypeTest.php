<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Fieldtypes\ContentTranslatorFieldtype;
use Statamic\Fields\Field;
use Tests\StatamicTestHelpers;
use Tests\TestCase;

// Use the full Statamic test case so the addon is booted (fieldtype registered,
// sites configured, etc.).
uses(TestCase::class);
uses(StatamicTestHelpers::class);

// ── Handle ────────────────────────────────────────────────────────────────────

it('has the handle content_translator', function () {
    expect(ContentTranslatorFieldtype::handle())->toBe('content_translator');
});

// ── Selectable ────────────────────────────────────────────────────────────────

it('is not selectable in the blueprint editor', function () {
    $fieldtype = new ContentTranslatorFieldtype;

    expect($fieldtype->selectable())->toBeFalse();
});

// ── preProcess / process passthrough ─────────────────────────────────────────

it('preProcess returns data unchanged', function () {
    $fieldtype = new ContentTranslatorFieldtype;

    expect($fieldtype->preProcess('hello'))->toBe('hello');
    expect($fieldtype->preProcess(null))->toBeNull();
    expect($fieldtype->preProcess(['foo' => 'bar']))->toBe(['foo' => 'bar']);
});

it('process returns data unchanged', function () {
    $fieldtype = new ContentTranslatorFieldtype;

    expect($fieldtype->process('hello'))->toBe('hello');
    expect($fieldtype->process(null))->toBeNull();
    expect($fieldtype->process(['foo' => 'bar']))->toBe(['foo' => 'bar']);
});

// ── preload — no entry (blueprint editor context) ─────────────────────────────

it('preload returns empty structure when no parent entry is set', function () {
    $fieldtype = new ContentTranslatorFieldtype;

    // Attach a field that has no parent (null).
    $field = new Field('content_translator', ['type' => 'content_translator']);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    expect($result)->toHaveKeys(['current_site', 'is_origin', 'sites']);
    expect($result['current_site'])->toBeNull();
    expect($result['is_origin'])->toBeFalse();
    expect($result['sites'])->toBeArray()->not->toBeEmpty();

    // Every site entry should have the required keys.
    foreach ($result['sites'] as $site) {
        expect($site)->toHaveKeys(['handle', 'name', 'exists', 'last_translated_at', 'is_stale']);
        expect($site['exists'])->toBeFalse();
        expect($site['last_translated_at'])->toBeNull();
        expect($site['is_stale'])->toBeFalse();
    }
});

// ── preload — with a live origin entry ───────────────────────────────────────

it('preload returns current_site and is_origin true for a root entry', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    expect($result['current_site'])->toBe('en');
    expect($result['is_origin'])->toBeTrue();
});

it('preload returns a sites array with all configured sites', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $handles = array_column($result['sites'], 'handle');
    expect($handles)->toContain('en')->toContain('fr');
});

it('preload marks a site as existing when a localization exists', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    // Create a French localization.
    $frLocalization = $entry->makeLocalization('fr');
    $frLocalization->save();

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');
    expect($frSite['exists'])->toBeTrue();
});

it('preload reports last_translated_at from the localization metadata', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $translatedAt = '2024-06-01T12:00:00+00:00';

    $frLocalization = $entry->makeLocalization('fr');
    $frLocalization->set('content_translator', ['last_translated_at' => $translatedAt]);
    $frLocalization->save();

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');
    expect($frSite['last_translated_at'])->toBe($translatedAt);
});

it('preload handles malformed last_translated_at metadata without crashing', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $frLocalization = $entry->makeLocalization('fr');
    $frLocalization->set('content_translator', ['last_translated_at' => 'not-a-date']);
    $frLocalization->save();

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');
    expect($frSite['last_translated_at'])->toBeNull();
    expect($frSite['is_stale'])->toBeFalse();
});

it('preload marks a localization as stale when origin was modified after last translation', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    // Simulate an old translation timestamp.
    $oldTranslatedAt = now()->subHour()->toIso8601String();

    $frLocalization = $entry->makeLocalization('fr');
    $frLocalization->set('content_translator', ['last_translated_at' => $oldTranslatedAt]);
    $frLocalization->save();

    // Now simulate the origin being updated after translation.
    $entry->set('updated_at', now()->timestamp);
    $entry->save();

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');
    expect($frSite['is_stale'])->toBeTrue();
});

it('preload marks a fresh translation as not stale', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    // Set updated_at in the past.
    $entry->set('updated_at', now()->subHour()->timestamp);
    $entry->save();

    // Translation happened more recently than the origin update.
    $recentTranslation = now()->toIso8601String();

    $frLocalization = $entry->makeLocalization('fr');
    $frLocalization->set('content_translator', ['last_translated_at' => $recentTranslation]);
    $frLocalization->save();

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $frSite = collect($result['sites'])->firstWhere('handle', 'fr');
    expect($frSite['is_stale'])->toBeFalse();
});

// ── preload — localized entry (non-origin) ────────────────────────────────────

// ── preload — authorization filtering ─────────────────────────────────────────

it('preload returns only sites the user can access for editing', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $user = $this->createRestrictedUser([
        'access en site',
        'edit articles entries',
    ]);
    $this->loginUser($user);

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $handles = array_column($result['sites'], 'handle');
    expect($handles)->toBe(['en']);
});

it('preload returns empty sites when user lacks edit permission for the collection', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');

    $user = $this->createRestrictedUser([
        'access en site',
        'access fr site',
    ]);
    $this->loginUser($user);

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    expect($result['sites'])->toBe([]);
});

it('preload returns only collection sites, ignoring unrelated Statamic sites', function () {
    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'es' => ['name' => 'Spanish', 'url' => 'http://localhost/es/', 'locale' => 'es'],
    ]);

    // Collection only configured for en + fr.
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry(collection: 'articles', site: 'en');
    $this->loginUser(); // super

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($entry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    $handles = array_column($result['sites'], 'handle');
    expect($handles)->toEqualCanonicalizing(['en', 'fr']);
});

it('preload returns is_origin false for a localized entry', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $originEntry = $this->createTestEntry(collection: 'articles', site: 'en');

    $frLocalization = $originEntry->makeLocalization('fr');
    $frLocalization->save();

    // Reload from stache so origin relationship is resolved.
    $frEntry = Statamic\Facades\Entry::find($frLocalization->id());

    $fieldtype = new ContentTranslatorFieldtype;
    $field = (new Field('content_translator', ['type' => 'content_translator']))
        ->setParent($frEntry);
    $fieldtype->setField($field);

    $result = $fieldtype->preload();

    expect($result['current_site'])->toBe('fr');
    expect($result['is_origin'])->toBeFalse();
});
