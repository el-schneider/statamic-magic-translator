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

it('is visible for an entry in a configured collection with multiple sites', function () {
    config(['statamic.content-translator.collections' => ['articles']]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    expect(makeAction()->visibleTo($entry))->toBeTrue();
});

it('is not visible for an entry in an unconfigured collection', function () {
    config(['statamic.content-translator.collections' => ['news']]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    expect(makeAction()->visibleTo($entry))->toBeFalse();
});

it('is not visible when collections config is empty', function () {
    config(['statamic.content-translator.collections' => []]);

    test()->createTestCollection('articles', ['en', 'fr']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    expect(makeAction()->visibleTo($entry))->toBeFalse();
});

it('is not visible for a single-site setup', function () {
    config(['statamic.content-translator.collections' => ['articles']]);

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
    config(['statamic.content-translator.collections' => ['articles']]);

    // Pass a plain object (not an Entry instance).
    expect(makeAction()->visibleTo(new stdClass()))->toBeFalse();
});

// ── run ───────────────────────────────────────────────────────────────────────

it('returns a callback with the entry IDs', function () {
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
    test()->createTestCollection('articles', ['en', 'fr']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en', slug: 'entry-solo');

    $result = makeAction()->run(collect([$entry]), []);

    // The second element of the callback tuple must be a plain indexed list.
    expect(array_is_list($result['callback'][1]))->toBeTrue();
});

// ── authorize ─────────────────────────────────────────────────────────────────

it('authorizes a super user to run the action', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');
    $superUser = test()->createTestUser(); // createTestUser() sets super=true

    expect(makeAction()->authorize($superUser, $entry))->toBeTrue();
});

it('authorizes a user with the correct edit permission', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    // In multisite, users also need "access {site} site" to pass the policy.
    $user = makeNonSuperUser('editor-ok', ['access cp', 'access en site', 'edit articles entries']);

    expect(makeAction()->authorize($user, $entry))->toBeTrue();
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
