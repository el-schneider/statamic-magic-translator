<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Data\TranslationUnit;
use ElSchneider\MagicTranslator\Jobs\TranslateEntryJob;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Entry;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

it('errors out when no filter is provided', function () {
    $this->artisan('statamic:magic-translator:translate')
        ->expectsOutputToContain('at least one filter')
        ->assertExitCode(2);
});

it('errors on unknown collection handle', function () {
    $this->artisan('statamic:magic-translator:translate', [
        '--collection' => ['nonexistent'],
        '--to' => ['fr'],
    ])
        ->expectsOutputToContain("Unknown collection 'nonexistent'")
        ->assertExitCode(2);
});

it('prints plan summary on --dry-run and exits 0 without executing', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr'],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('translation plan')
        ->expectsOutputToContain('will translate')
        ->expectsOutputToContain('Dry run — no changes made')
        ->assertExitCode(0);
});

it('prints empty plan when no entries match filter', function () {
    $this->createTestCollection('articles', ['en', 'fr']);

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr'],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('No translations to perform')
        ->assertExitCode(0);
});

it('aborts gracefully when user answers no at confirm prompt', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr'],
    ])
        ->expectsConfirmation('Proceed?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertExitCode(0);
});

it('proceeds past confirm with --no-interaction', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr'],
        '--no-interaction' => true,
    ])
        ->assertExitCode(0);
});

function bindPrefixService(string $prefix = 'FR: '): void
{
    $mock = Mockery::mock(TranslationService::class);
    $mock->shouldReceive('translate')
        ->andReturnUsing(fn (array $units) => array_map(
            fn (TranslationUnit $u) => $u->withTranslation($prefix.$u->text),
            $units,
        ));

    app()->instance(TranslationService::class, $mock);
}

it('executes sync translation and reports success summary', function () {
    bindPrefixService('FR: ');

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $en = $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr'],
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Translation summary')
        ->expectsOutputToContain('Succeeded:   1')
        ->expectsOutputToContain('Failed:       0')
        ->assertExitCode(0);

    $localized = Entry::find($en->id())->in('fr');
    expect($localized)->not->toBeNull();
    expect($localized->get('title'))->toBe('FR: Test Entry');
});

it('reports partial failure and exits 1 when a translation throws', function () {
    $mock = Mockery::mock(TranslationService::class);
    $mock->shouldReceive('translate')
        ->andThrow(new RuntimeException('provider exploded'));

    app()->instance(TranslationService::class, $mock);

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr'],
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Translation summary')
        ->expectsOutputToContain('Failed:       1')
        ->expectsOutputToContain('provider exploded')
        ->assertExitCode(1);
});

it('dispatches a job per processable pair when --dispatch-jobs is set', function () {
    Queue::fake();

    $this->createMultiSiteSetup([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    $this->createTestCollection('articles', ['en', 'fr', 'de']);
    $this->createTestBlueprint('articles');
    $this->createTestEntry(collection: 'articles', site: 'en');

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr', 'de'],
        '--dispatch-jobs' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Dispatched 2 job')
        ->assertExitCode(0);

    Queue::assertPushed(TranslateEntryJob::class, 2);
});

it('dispatches zero jobs when plan is empty', function () {
    Queue::fake();

    $this->createTestCollection('articles', ['en', 'fr']);

    $this->artisan('statamic:magic-translator:translate', [
        '--to' => ['fr'],
        '--dispatch-jobs' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('No translations to perform')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});
