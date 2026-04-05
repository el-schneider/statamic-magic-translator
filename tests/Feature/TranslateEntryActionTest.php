<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\StatamicActions\TranslateEntryAction;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAction(): TranslateEntryAction
{
    return app(TranslateEntryAction::class);
}

function makeNonSuperUser(string $id = 'regular-user', array $permissions = []): Statamic\Auth\User
{
    $role = Role::make('test-role-'.$id)->permissions($permissions);
    $role->save();

    $user = User::make()
        ->id($id)
        ->email($id.'@example.com')
        ->set('name', 'Regular User')
        ->set('super', false)
        ->password('password')
        ->assignRole($role);

    $user->save();

    return $user;
}

// ── visibleTo ─────────────────────────────────────────────────────────────────

it('is visible for an entry with multiple sites', function () {
    test()->loginUser(); // super
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    expect(makeAction()->visibleTo($entry))->toBeTrue();
});

it('is not visible for an excluded exact blueprint', function () {
    test()->loginUser();
    config(['statamic.content-translator.exclude_blueprints' => ['articles.default']]);

    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    expect(makeAction()->visibleTo($entry))->toBeFalse();
});

it('is not visible for an excluded wildcard blueprint pattern', function () {
    test()->loginUser();
    config(['statamic.content-translator.exclude_blueprints' => ['articles.*']]);

    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    expect(makeAction()->visibleTo($entry))->toBeFalse();
});

it('is not visible for a single-site setup', function () {
    // Reduce to a single site only.
    Site::setSites([
        'en' => [
            'name' => 'English',
            'url' => 'http://localhost/',
            'locale' => 'en',
        ],
    ]);

    test()->createTestCollection('articles', ['en']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    expect(makeAction()->visibleTo($entry))->toBeFalse();
});

it('is not visible for non-Entry items', function () {
    // Pass a plain object (not an Entry instance).
    expect(makeAction()->visibleTo(new stdClass()))->toBeFalse();
});

it('is not visible when user has no accessible target sites', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    // User can edit in source site but cannot access any other site.
    $user = test()->createRestrictedUser([
        'access cp',
        'access en site',
        'edit articles entries',
    ]);
    test()->loginUser($user);

    expect(makeAction()->visibleTo($entry))->toBeFalse();
});

it('is not visible when user is not authenticated', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    // No loginUser() call.
    expect(makeAction()->visibleTo($entry))->toBeFalse();
});

// ── run ───────────────────────────────────────────────────────────────────────

it('returns a callback with the entry IDs', function () {
    test()->loginUser();
    test()->createTestCollection('articles', ['en', 'fr']);
    $entry1 = test()->createTestEntry(collection: 'articles', site: 'en', slug: 'entry-one');
    $entry2 = test()->createTestEntry(collection: 'articles', site: 'en', slug: 'entry-two');

    $items = collect([$entry1, $entry2]);
    $result = makeAction()->run($items, []);

    expect($result)->toBeArray()
        ->toHaveKey('callback')
        ->and($result['callback'][0])->toBe('openTranslationDialog')
        ->and($result['callback'][1])->toBe([$entry1->id(), $entry2->id()]);
});

it('returns an indexed (non-associative) array of entry IDs', function () {
    test()->loginUser();
    test()->createTestCollection('articles', ['en', 'fr']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en', slug: 'entry-solo');

    $result = makeAction()->run(collect([$entry]), []);

    // The second element of the callback tuple must be a plain indexed list.
    expect(array_is_list($result['callback'][1]))->toBeTrue();
});

it('passes only user-accessible sites to the dialog', function () {
    \Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    $user = test()->createRestrictedUser([
        'access cp',
        'access en site',
        'access fr site',
        'edit articles entries',
    ]);
    test()->loginUser($user);

    test()->createTestCollection('articles', ['en', 'fr', 'de']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $result = makeAction()->run(collect([$entry]), []);

    // Includes source locale (en) — client strips it when user picks source.
    $siteHandles = array_column($result['callback'][2], 'handle');
    expect($siteHandles)->toEqualCanonicalizing(['en', 'fr']);
});

it('intersects accessible sites across multiple collections', function () {
    test()->loginUser(); // super sees all

    \Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    test()->createTestCollection('articles', ['en', 'fr', 'de']);
    test()->createTestCollection('pages', ['en', 'fr']);

    $article = test()->createTestEntry(collection: 'articles', site: 'en', slug: 'article-1');
    $page = test()->createTestEntry(collection: 'pages', site: 'en', slug: 'page-1');

    $result = makeAction()->run(collect([$article, $page]), []);

    // Intersection of [en, fr, de] (articles) and [en, fr] (pages) = [en, fr].
    $siteHandles = array_column($result['callback'][2], 'handle');
    expect($siteHandles)->toEqualCanonicalizing(['en', 'fr']);
});

// ── authorize ─────────────────────────────────────────────────────────────────

it('authorizes a super user to run the action', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');
    $superUser = test()->createTestUser(); // createTestUser() sets super=true

    expect(makeAction()->authorize($superUser, $entry))->toBeTrue();
});

it('authorizes a user with the correct edit permission and an accessible target', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    // User needs edit + source-site access + at least one accessible target.
    $user = makeNonSuperUser('editor-ok', [
        'access cp',
        'access en site',
        'access fr site',
        'edit articles entries',
    ]);

    expect(makeAction()->authorize($user, $entry))->toBeTrue();
});

it('denies a user with edit permission but no accessible target sites', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    // User can edit in source site but cannot access any other site.
    $user = makeNonSuperUser('editor-en-only', [
        'access cp',
        'access en site',
        'edit articles entries',
    ]);

    expect(makeAction()->authorize($user, $entry))->toBeFalse();
});

it('denies a user without edit permission', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $user = makeNonSuperUser('view-only', ['access cp', 'access en site', 'view articles entries']);

    expect(makeAction()->authorize($user, $entry))->toBeFalse();
});

// ── title ─────────────────────────────────────────────────────────────────────

it('returns a non-empty title string', function () {
    expect(TranslateEntryAction::title())->toBeString()->not->toBeEmpty();
});

// ── confirmation ──────────────────────────────────────────────────────────────

it('bypasses the default confirmation dialog', function () {
    // The action uses $confirm = false because it opens its own custom dialog
    $action = app(TranslateEntryAction::class);
    $reflected = new ReflectionProperty($action, 'confirm');
    expect($reflected->getValue($action))->toBeFalse();
});
