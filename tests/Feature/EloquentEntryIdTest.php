<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Console\FilterCriteria;
use ElSchneider\MagicTranslator\Console\TranslationPlanner;
use ElSchneider\MagicTranslator\Fieldtypes\MagicTranslatorFieldtype;
use ElSchneider\MagicTranslator\Http\Controllers\TranslationController;
use ElSchneider\MagicTranslator\Jobs\TranslateEntryJob;
use ElSchneider\MagicTranslator\StatamicActions\TranslateEntryAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Entry;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

/**
 * The Eloquent driver hands out auto-increment integer ids instead of the
 * string uuids the flat-file driver uses. Those ids reach the addon as JSON
 * numbers from the Control Panel and as native ints from the entry objects.
 */

/**
 * Build a saved entry whose id is numeric, mimicking the Eloquent driver.
 */
function numericIdEntry(string $collection = 'articles', int $id = 48): Statamic\Entries\Entry
{
    $entry = Entry::make()
        ->collection($collection)
        ->locale('en')
        ->slug('numeric-id-entry')
        ->id($id)
        ->data(['title' => 'Numeric Id Entry']);

    $entry->save();

    return $entry;
}

function cpRouteUrl(string $path): string
{
    $prefix = mb_ltrim(config('statamic.cp.route', 'cp'), '/');

    return "/{$prefix}/{$path}";
}

it('accepts an integer entry id when triggering a translation', function () {
    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = numericIdEntry();

    $response = $this->postJson(cpRouteUrl('magic-translator/translate'), [
        'entry_id' => (int) $entry->id(),
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);

    Queue::assertPushed(TranslateEntryJob::class, 1);
});

it('accepts an integer entry id when marking a locale as current', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = numericIdEntry();
    $entry->makeLocalization('fr')->save();

    $response = $this->postJson(cpRouteUrl('magic-translator/mark-current'), [
        'entry_id' => (int) $entry->id(),
        'locale' => 'fr',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
});

it('hands the entry id to the Control Panel as a string', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = numericIdEntry();

    $fieldtype = new MagicTranslatorFieldtype;
    $fieldtype->setField(
        (new Statamic\Fields\Field('magic_translator', ['type' => 'magic_translator']))->setParent($entry)
    );

    expect($fieldtype->preload()['entry_id'])->toBeString()->toBe('48');
});

it('hands bulk action entry ids to the Control Panel as strings', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = numericIdEntry();

    $action = new TranslateEntryAction;
    $action->context(['view' => 'list']);

    $response = $action->run(collect([$entry]), []);

    expect($response['callback'][1])->toBe(['48']);
});

it('plans entries with a numeric id without a type error', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    numericIdEntry();

    $plan = app(TranslationPlanner::class)->plan(new FilterCriteria(
        targetSites: ['fr'],
        sourceSite: null,
        collections: ['articles'],
        entryIds: [],
        blueprints: [],
        includeStale: false,
        overwrite: false,
    ));

    expect($plan->processable())->not->toBeEmpty()
        ->and($plan->processable()[0]->entryId)->toBe('48');
});

/**
 * With the Eloquent user driver the guard hands back the application's own user
 * model, which is not a Statamic user. It must be resolved through Statamic's
 * repository before it reaches the permission helpers, which are typed against
 * Statamic's user contract and otherwise fail with a TypeError.
 *
 * These tests call the controller directly on purpose: the CP middleware
 * resolves the user itself, which would mask whether the controller does.
 */

/**
 * Stand-in for an application user model that Statamic's file driver cannot
 * resolve into one of its own users.
 */
function foreignUser(): object
{
    return new class
    {
        public function can(string $ability, mixed $arguments = null): bool
        {
            return true;
        }

        public function id(): int
        {
            return 1;
        }
    };
}

function jsonRequestWithForeignUser(string $path, array $payload): Request
{
    $request = Request::create(
        $path,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode($payload)
    );

    $request->setUserResolver(fn () => foreignUser());

    return $request;
}

it('does not crash when the guard returns a user Statamic cannot resolve', function () {
    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = numericIdEntry();

    $response = app(TranslationController::class)->trigger(
        jsonRequestWithForeignUser('/magic-translator/translate', [
            'entry_id' => (int) $entry->id(),
            'target_sites' => ['fr'],
        ])
    );

    // The file driver cannot convert a foreign user, so the request is refused
    // instead of blowing up. On the Eloquent driver the same call converts.
    expect($response->getStatusCode())->toBe(401);
});

it('does not crash on mark-current when the guard returns an unresolvable user', function () {
    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = numericIdEntry();
    $entry->makeLocalization('fr')->save();

    $response = app(TranslationController::class)->markCurrent(
        jsonRequestWithForeignUser('/magic-translator/mark-current', [
            'entry_id' => (int) $entry->id(),
            'locale' => 'fr',
        ])
    );

    expect($response->getStatusCode())->toBe(401);
});
