<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Support\AccessibleSites;
use Statamic\Facades\Site;
use Tests\StatamicTestHelpers;
use Tests\TestCase;

uses(TestCase::class);
uses(StatamicTestHelpers::class);

beforeEach(function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    $this->collection = $this->createTestCollection('articles', ['en', 'fr', 'de']);
});

// ── forCollection ─────────────────────────────────────────────────────────────

it('returns all collection sites for a super user', function () {
    $user = $this->createTestUser(); // super

    $result = AccessibleSites::forCollection($user, $this->collection);

    expect($result->all())->toEqualCanonicalizing(['en', 'fr', 'de']);
});

it('returns only user-accessible sites from the collection sites', function () {
    $user = $this->createRestrictedUser([
        'access en site',
        'access fr site',
        'edit articles entries',
    ]);

    $result = AccessibleSites::forCollection($user, $this->collection);

    expect($result->all())->toEqualCanonicalizing(['en', 'fr']);
});

it('returns empty when user has site access but no edit entries permission', function () {
    $user = $this->createRestrictedUser([
        'access en site',
        'access fr site',
        'access de site',
        // no edit articles entries
    ]);

    $result = AccessibleSites::forCollection($user, $this->collection);

    expect($result->all())->toBe([]);
});

it('returns empty when user has edit entries permission but no site access', function () {
    $user = $this->createRestrictedUser([
        'edit articles entries',
    ]);

    $result = AccessibleSites::forCollection($user, $this->collection);

    expect($result->all())->toBe([]);
});

it('returns empty when site does not overlap with collection sites', function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'es' => ['name' => 'Spanish', 'url' => 'http://localhost/es/', 'locale' => 'es'],
    ]);

    $user = $this->createRestrictedUser([
        'access es site', // not in the articles collection
        'edit articles entries',
    ]);

    $result = AccessibleSites::forCollection($user, $this->collection);

    expect($result->all())->toBe([]);
});

it('returns empty when multi-site is disabled', function () {
    config()->set('statamic.system.multisite', false);

    $user = $this->createTestUser(); // super

    $result = AccessibleSites::forCollection($user, $this->collection);

    expect($result->all())->toBe([]);
});

// ── forTranslationTargets ─────────────────────────────────────────────────────

it('excludes the source site from accessible targets', function () {
    $user = $this->createTestUser(); // super

    $result = AccessibleSites::forTranslationTargets($user, $this->collection, 'en');

    expect($result->all())->toEqualCanonicalizing(['fr', 'de']);
});

it('returns all accessible sites when no source is excluded', function () {
    $user = $this->createTestUser(); // super

    $result = AccessibleSites::forTranslationTargets($user, $this->collection);

    expect($result->all())->toEqualCanonicalizing(['en', 'fr', 'de']);
});

it('returns empty when only accessible site is the excluded source', function () {
    $user = $this->createRestrictedUser([
        'access en site',
        'edit articles entries',
    ]);

    $result = AccessibleSites::forTranslationTargets($user, $this->collection, 'en');

    expect($result->all())->toBe([]);
});

it('combines restricted access with source exclusion correctly', function () {
    $user = $this->createRestrictedUser([
        'access en site',
        'access fr site',
        'edit articles entries',
    ]);

    $result = AccessibleSites::forTranslationTargets($user, $this->collection, 'en');

    expect($result->all())->toBe(['fr']);
});
