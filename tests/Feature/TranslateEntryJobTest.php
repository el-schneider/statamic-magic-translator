<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Actions\TranslateEntry;
use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use ElSchneider\ContentTranslator\Events\AfterEntryTranslation;
use ElSchneider\ContentTranslator\Events\BeforeEntryTranslation;
use ElSchneider\ContentTranslator\Exceptions\ProviderAuthException;
use ElSchneider\ContentTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\ContentTranslator\Jobs\TranslateEntryJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a mock TranslationService that prefixes each unit's text with "FR: ".
 */
function makePrefixTranslationService(string $prefix = 'FR: '): TranslationService
{
    $mock = Mockery::mock(TranslationService::class);

    $mock->shouldReceive('translate')
        ->andReturnUsing(function (array $units) use ($prefix): array {
            return array_map(
                fn (TranslationUnit $u) => $u->withTranslation($prefix.$u->text),
                $units,
            );
        });

    return $mock;
}

/**
 * Set up a typical test: collection + blueprint + entry (en), mock service bound.
 *
 * @return array{entry: Statamic\Entries\Entry, action: TranslateEntry}
 */
function setUpTranslationTest(
    array $entryData = [],
    array $blueprintFields = [],
): array {
    test()->createTestCollection('articles', ['en', 'fr']);
    test()->createTestBlueprint('articles', 'default', $blueprintFields);

    $defaultData = ['title' => 'Hello World', 'meta_description' => 'A great post'];

    $entry = test()->createTestEntry(
        collection: 'articles',
        data: array_merge($defaultData, $entryData),
        site: 'en',
    );

    $mock = makePrefixTranslationService();
    app()->instance(TranslationService::class, $mock);

    $action = app(TranslateEntry::class);

    return compact('entry', 'action');
}

// ── Core translation ──────────────────────────────────────────────────────────

it('translates an entry to a target locale', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    $action->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');

    expect($fr)->not->toBeNull();
    expect($fr->get('title'))->toBe('FR: Hello World');
    expect($fr->get('meta_description'))->toBe('FR: A great post');
});

it('creates localization when it does not exist', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    expect($entry->in('fr'))->toBeNull();

    $action->handle($entry->id(), 'fr');

    expect($entry->in('fr'))->not->toBeNull();
});

it('overwrites existing localization by default', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    // First translation
    $action->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');
    expect($fr->get('title'))->toBe('FR: Hello World');

    // Update source and translate again (overwrite = default true)
    $entry->set('title', 'Updated Title')->save();

    app()->instance(TranslationService::class, makePrefixTranslationService('FR2: '));
    $action = app(TranslateEntry::class);
    $action->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');
    expect($fr->get('title'))->toBe('FR2: Updated Title');
});

it('skips existing localization when overwrite is false', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    // First translation
    $action->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');
    $firstTitle = $fr->get('title');
    expect($firstTitle)->toBe('FR: Hello World');

    // Update source and try to translate again with overwrite=false
    $entry->set('title', 'Updated Title')->save();

    app()->instance(TranslationService::class, makePrefixTranslationService('FR2: '));
    $action = app(TranslateEntry::class);
    $action->handle($entry->id(), 'fr', null, ['overwrite' => false]);

    $fr = Entry::find($entry->id())->in('fr');
    // Title should NOT have changed
    expect($fr->get('title'))->toBe($firstTitle);
});

// ── Metadata ──────────────────────────────────────────────────────────────────

it('sets last_translated_at on the content_translator field', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    $before = now()->subSecond(); // subtract 1s to avoid equal-timestamp edge case

    $action->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');
    $meta = $fr->get('content_translator');

    expect($meta)->toBeArray();
    expect($meta)->toHaveKey('last_translated_at');

    $timestamp = Carbon\Carbon::parse($meta['last_translated_at']);
    expect($timestamp->gte($before))->toBeTrue();
});

// ── Slug regeneration ─────────────────────────────────────────────────────────

it('regenerates slug from translated title when generate_slug is true', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest([
        'title' => 'My English Title',
    ]);

    $action->handle($entry->id(), 'fr', null, ['generate_slug' => true]);

    $fr = Entry::find($entry->id())->in('fr');
    $translatedTitle = $fr->get('title');

    expect($translatedTitle)->toBe('FR: My English Title');
    // Slug should be derived from translated title
    expect($fr->slug())->toBe(Illuminate\Support\Str::slug($translatedTitle));
});

it('does not regenerate slug when generate_slug is false', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest([
        'title' => 'My English Title',
    ]);

    // Entry has slug 'test-entry'
    $originalSlug = $entry->slug();

    $action->handle($entry->id(), 'fr', null, ['generate_slug' => false]);

    $fr = Entry::find($entry->id())->in('fr');
    // When localization is created, it inherits the origin's slug
    expect($fr->slug())->toBe($originalSlug);
});

// ── Events ────────────────────────────────────────────────────────────────────

it('fires BeforeEntryTranslation event before extraction', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    $firedBefore = false;

    Event::listen(BeforeEntryTranslation::class, function (BeforeEntryTranslation $event) use (&$firedBefore, $entry) {
        $firedBefore = true;
        expect($event->entry->id())->toBe($entry->id());
        expect($event->targetSite)->toBe('fr');
        expect($event->units)->toBeArray();
    });

    $action->handle($entry->id(), 'fr');

    expect($firedBefore)->toBeTrue();
});

it('fires AfterEntryTranslation event after translation', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    $firedAfter = false;

    Event::listen(AfterEntryTranslation::class, function (AfterEntryTranslation $event) use (&$firedAfter) {
        $firedAfter = true;
        expect($event->targetSite)->toBe('fr');
        expect($event->translatedData)->toBeArray();
        expect($event->translatedData)->toHaveKey('title');
    });

    $action->handle($entry->id(), 'fr');

    expect($firedAfter)->toBeTrue();
});

it('allows BeforeEntryTranslation listener to modify units', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    // Listener removes all units → nothing gets translated
    Event::listen(BeforeEntryTranslation::class, function (BeforeEntryTranslation $event) {
        $event->units = [];
    });

    $action->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');
    // With no units to translate, the fr entry data should be empty (no translated fields)
    expect($fr->get('title'))->toBeNull();
});

it('allows AfterEntryTranslation listener to modify translated data', function () {
    ['entry' => $entry, 'action' => $action] = setUpTranslationTest();

    Event::listen(AfterEntryTranslation::class, function (AfterEntryTranslation $event) {
        $event->translatedData['title'] = 'Overridden by listener';
    });

    $action->handle($entry->id(), 'fr');

    $fr = Entry::find($entry->id())->in('fr');
    expect($fr->get('title'))->toBe('Overridden by listener');
});

// ── Source site override ──────────────────────────────────────────────────────

it('translates from a specified source site instead of origin', function () {
    // Set up three sites: en, fr, es.
    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'es' => ['name' => 'Spanish', 'url' => 'http://localhost/es/', 'locale' => 'es'],
    ]);

    test()->createTestCollection('articles', ['en', 'fr', 'es']);
    test()->createTestBlueprint('articles', 'default');

    // Origin entry (en)
    $originEntry = test()->createTestEntry(
        collection: 'articles',
        data: ['title' => 'English Title', 'meta_description' => 'English meta'],
        site: 'en',
        slug: 'source-entry',
    );

    // Create fr localization manually with different content
    $frEntry = $originEntry->makeLocalization('fr');
    $frEntry->data(['title' => 'Titre Français', 'meta_description' => 'Meta française']);
    $frEntry->save();

    // Mock service to prefix with "ES: "
    $mock = makePrefixTranslationService('ES: ');
    app()->instance(TranslationService::class, $mock);

    $action = app(TranslateEntry::class);

    // Translate fr → es (using fr as source)
    $action->handle($originEntry->id(), 'es', 'fr');

    $esEntry = Entry::find($originEntry->id())->in('es');

    expect($esEntry)->not->toBeNull();
    // Should have translated FROM fr content, not en
    expect($esEntry->get('title'))->toBe('ES: Titre Français');
    expect($esEntry->get('meta_description'))->toBe('ES: Meta française');
});

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
    config()->set('statamic.content-translator.queue.connection', 'redis');
    config()->set('statamic.content-translator.queue.name', 'content-translator');

    $job = new TranslateEntryJob('some-id', 'fr');

    expect($job->connection)->toBe('redis');
    expect($job->queue)->toBe('content-translator');
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

    Cache::put("content-translator:job:{$jobId}", [
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

    $cached = Cache::get("content-translator:job:{$jobId}");
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

    Cache::put("content-translator:job:{$jobId}", [
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

    $cached = Cache::get("content-translator:job:{$jobId}");
    expect($cached['status'])->toBe('failed');
    expect($cached['error'])->toBe([
        'code' => 'provider_auth_failed',
        'message' => 'Translation service authentication failed.',
        'message_key' => 'content-translator::messages.error_provider_auth_failed',
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

    Cache::put("content-translator:job:{$jobId}", [
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

    $cached = Cache::get("content-translator:job:{$jobId}");
    expect($cached['status'])->toBe('failed');
    expect($cached['error']['code'])->toBe('provider_rate_limited');
    expect($cached['error']['retryable'])->toBeTrue();
});
