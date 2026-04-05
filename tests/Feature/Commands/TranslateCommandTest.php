<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use Statamic\Facades\Entry;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

it('errors out when no filter is provided', function () {
    $this->artisan('statamic:content-translator:translate')
        ->expectsOutputToContain('at least one filter')
        ->assertExitCode(2);
});

it('errors on unknown collection handle', function () {
    $this->artisan('statamic:content-translator:translate', [
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

    $this->artisan('statamic:content-translator:translate', [
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

    $this->artisan('statamic:content-translator:translate', [
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

    $this->artisan('statamic:content-translator:translate', [
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

    $this->artisan('statamic:content-translator:translate', [
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

    $this->artisan('statamic:content-translator:translate', [
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

    $this->artisan('statamic:content-translator:translate', [
        '--to' => ['fr'],
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Translation summary')
        ->expectsOutputToContain('Failed:       1')
        ->expectsOutputToContain('provider exploded')
        ->assertExitCode(1);
});
