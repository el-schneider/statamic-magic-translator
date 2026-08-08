<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Actions\TranslateEntry;
use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Exceptions\ProviderAuthException;
use ElSchneider\MagicTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\MagicTranslator\Jobs\TranslateEntryJob;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Entry;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

// ── Job wrapper ───────────────────────────────────────────────────────────────

it('TranslateEntryJob delegates to TranslateEntry action', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');

    $entry = test()->createTestEntry(
        collection: 'articles',
        data: ['title' => 'Job Test Entry', 'meta_description' => 'Job test meta'],
        site: 'en',
    );

    $mock = makePrefixTranslationService();
    app()->instance(TranslationService::class, $mock);

    // Dispatch synchronously
    $job = new TranslateEntryJob($entry->id(), 'fr');
    app()->call([$job, 'handle']);

    $fr = Entry::find($entry->id())->in('fr');
    expect($fr)->not->toBeNull();
    expect($fr->get('title'))->toBe('FR: Job Test Entry');
});

it('TranslateEntryJob has correct retry configuration', function () {
    $job = new TranslateEntryJob('some-id', 'fr');

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 60, 120]);
});

it('TranslateEntryJob applies queue connection and name from config', function () {
    config()->set('statamic.magic-translator.queue.connection', 'redis');
    config()->set('statamic.magic-translator.queue.name', 'magic-translator');

    $job = new TranslateEntryJob('some-id', 'fr');

    expect($job->connection)->toBe('redis');
    expect($job->queue)->toBe('magic-translator');
});

it('TranslateEntryJob is a no-op when the entry was deleted before execution', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');

    $entry = test()->createTestEntry(collection: 'articles', site: 'en');
    $entryId = $entry->id();

    $entry->delete();

    $job = new TranslateEntryJob($entryId, 'fr');

    expect(fn () => app()->call([$job, 'handle']))->not->toThrow(Exception::class);
});

it('builds nested blueprint field definitions so replicator set fields are translated', function () {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default', [
        'title' => [
            'type' => 'text',
            'localizable' => true,
        ],
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text_block' => [
                    'display' => 'Text Block',
                    'fields' => [
                        ['handle' => 'body', 'field' => ['type' => 'text']],
                        ['handle' => 'summary', 'field' => ['type' => 'textarea']],
                    ],
                ],
            ],
        ],
    ]);

    $entry = test()->createTestEntry(
        collection: 'articles',
        site: 'en',
        data: [
            'title' => 'Blueprint Test',
            'blocks' => [
                ['type' => 'text_block', 'body' => 'Nested body', 'summary' => 'Nested summary'],
            ],
        ],
    );

    app()->instance(TranslationService::class, makePrefixTranslationService('FR: '));

    app(TranslateEntry::class)->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');

    expect($fr->get('title'))->toBe('FR: Blueprint Test');
    expect($fr->get('blocks')[0]['body'])->toBe('FR: Nested body');
    expect($fr->get('blocks')[0]['summary'])->toBe('FR: Nested summary');
});

it('stores a structured unexpected error in cache when translation throws a generic exception', function () {
    app()->instance(TranslationService::class, new class implements TranslationService
    {
        public function translate(array $units, string $sourceLocale = 'en', string $targetLocale = 'fr'): array
        {
            throw new RuntimeException('Simulated API error');
        }
    });

    $jobId = 'generic-fail-job-test';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'pending',
        'error' => null,
    ], 600);

    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $job = new TranslateEntryJob($entry->id(), 'fr', null, [], $jobId);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

    $cached = Cache::get("magic-translator:job:{$jobId}");
    expect($cached['status'])->toBe('failed');
    expect($cached['error'])->toBe([
        'code' => 'unexpected_error',
        'message' => 'An unexpected error occurred.',
        'retryable' => false,
    ]);
});

it('stores the domain exception api error in cache when translation throws a content translator exception', function () {
    app()->instance(TranslationService::class, new class implements TranslationService
    {
        public function translate(array $units, string $sourceLocale = 'en', string $targetLocale = 'fr'): array
        {
            throw new ProviderAuthException('Provider authentication failed.', null, ['provider' => 'prism']);
        }
    });

    $jobId = 'domain-fail-job-test';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'pending',
        'error' => null,
    ], 600);

    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $job = new TranslateEntryJob($entry->id(), 'fr', null, [], $jobId);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(ProviderAuthException::class);

    $cached = Cache::get("magic-translator:job:{$jobId}");
    expect($cached['status'])->toBe('failed');
    expect($cached['error'])->toBe([
        'code' => 'provider_auth_failed',
        'message' => 'Translation service authentication failed.',
        'message_key' => 'magic-translator::messages.error_provider_auth_failed',
        'retryable' => false,
    ]);
});

it('preserves the retryable flag in cached structured job errors', function () {
    app()->instance(TranslationService::class, new class implements TranslationService
    {
        public function translate(array $units, string $sourceLocale = 'en', string $targetLocale = 'fr'): array
        {
            throw new ProviderRateLimitedException('Provider rate limit exceeded.', null, ['provider' => 'prism']);
        }
    });

    $jobId = 'retryable-domain-fail-job-test';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'pending',
        'error' => null,
    ], 600);

    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default');
    $entry = test()->createTestEntry(collection: 'articles', site: 'en');

    $job = new TranslateEntryJob($entry->id(), 'fr', null, [], $jobId);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(ProviderRateLimitedException::class);

    $cached = Cache::get("magic-translator:job:{$jobId}");
    expect($cached['status'])->toBe('failed');
    expect($cached['error']['code'])->toBe('provider_rate_limited');
    expect($cached['error']['retryable'])->toBeTrue();
});

it('marks the cached job failed when Laravel gives up after the final retry', function () {
    $jobId = 'exhausted-retries-job-test';

    // A retry cycle set the status back to 'running'; only failed() can move it
    // to the terminal state, so the CP stops polling.
    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'running',
        'error' => null,
    ], 600);

    (new TranslateEntryJob('entry-id', 'fr', null, [], $jobId))
        ->failed(new ProviderRateLimitedException('Provider rate limit exceeded.', null, ['provider' => 'prism']));

    $cached = Cache::get("magic-translator:job:{$jobId}");

    expect($cached['status'])->toBe('failed')
        ->and($cached['error']['code'])->toBe('provider_rate_limited');
});

it('marks the cached job failed with an unexpected error for non-domain exceptions', function () {
    $jobId = 'exhausted-retries-generic-job-test';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'running',
        'error' => null,
    ], 600);

    (new TranslateEntryJob('entry-id', 'fr', null, [], $jobId))->failed(new RuntimeException('boom'));

    $cached = Cache::get("magic-translator:job:{$jobId}");

    expect($cached['status'])->toBe('failed')
        ->and($cached['error']['code'])->toBe('unexpected_error');
});

it('clears stale cache errors when a retried job later succeeds', function () {
    app()->instance(TranslationService::class, makePrefixTranslationService());

    $jobId = 'retry-success-job-id';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'failed',
        'error' => 'Previous transient error',
    ], 600);

    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles');
    $entry = test()->createTestEntry('articles');

    $job = new TranslateEntryJob($entry->id(), 'fr', null, [], $jobId);
    app()->call([$job, 'handle']);

    $cached = Cache::get("magic-translator:job:{$jobId}");
    expect($cached['status'])->toBe('completed');
    expect($cached['error'] ?? null)->toBeNull();
});
