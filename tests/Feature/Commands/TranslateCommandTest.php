<?php

declare(strict_types=1);

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
