<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\MagicTranslator\Jobs\TranslateEntryJob;
use ElSchneider\MagicTranslator\Support\ContentFingerprint;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\StatamicTestHelpers;

uses(StatamicTestHelpers::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return the absolute URL for a CP route path.
 */
function cpUrl(string $path): string
{
    $prefix = mb_ltrim(config('statamic.cp.route', 'cp'), '/');

    return "/{$prefix}/{$path}";
}

/**
 * Minimal trigger payload for a valid request.
 */
function triggerPayload(string $entryId, array $targetSites = ['fr']): array
{
    return [
        'entry_id' => $entryId,
        'target_sites' => $targetSites,
    ];
}

/**
 * Minimal mark-current payload for a valid request.
 */
function markCurrentPayload(string $entryId, string $locale = 'fr'): array
{
    return [
        'entry_id' => $entryId,
        'locale' => $locale,
    ];
}

function sourceContentHashFor($entry): string
{
    $root = $entry->isRoot() ? $entry : $entry->root();
    $fieldDefs = FieldDefinitionBuilder::fromBlueprint($root->blueprint());

    return ContentFingerprint::compute($root->data()->all(), $fieldDefs);
}

// ── Authentication ─────────────────────────────────────────────────────────────

it('requires authentication to trigger a translation', function () {
    // No actingAs() call — unauthenticated JSON request.
    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => 'some-id',
        'target_sites' => ['fr'],
    ]);

    // Statamic's CP auth middleware returns 401 for JSON requests.
    $response->assertStatus(401);
});

it('requires authentication to check job status', function () {
    $response = $this->getJson(cpUrl('magic-translator/status'), [
        'jobs' => ['some-job-id'],
    ]);

    $response->assertStatus(401);
});

it('forbids users who cannot edit the specific entry', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
        'author' => ['type' => 'users', 'localizable' => false],
    ]);

    $author = Statamic\Facades\User::make()
        ->id('author-user')
        ->email('author@example.com')
        ->set('name', 'Author User')
        ->set('super', false)
        ->password('password');
    $author->save();

    $editor = Statamic\Facades\User::make()
        ->id('editor-user')
        ->email('editor@example.com')
        ->set('name', 'Editor User')
        ->set('super', false)
        ->password('password');

    $role = Statamic\Facades\Role::make('editor')->permissions([
        'access cp',
        'edit articles entries',
    ]);
    $role->save();

    $editor->assignRole('editor');
    $editor->save();

    $this->loginUser($editor);

    $entry = $this->createTestEntry('articles', [
        'author' => [$author->id()],
    ]);

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'forbidden',
                'message' => 'Forbidden.',
                'retryable' => false,
            ],
        ]);
});

it('forbids target sites the user cannot access', function () {
    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    $this->createTestCollection('articles', ['en', 'fr', 'de']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $user = $this->createRestrictedUser([
        'access cp',
        'access en site',
        'access fr site',
        'edit articles entries',
    ]);
    $this->loginUser($user);

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr', 'de'], // de is not accessible
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.retryable', false)
        ->assertJsonPath('error.message', 'Not authorized to translate into: de.');
});

it('forbids a source_site the user cannot access', function () {
    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    $this->createTestCollection('articles', ['en', 'fr', 'de']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles', site: 'en');

    // User has access to fr + de but not en.
    // They also cannot 'edit' the entry (which is in en), so this should
    // fail at the earlier $user->can('edit', $entry) check — EntryPolicy
    // requires access to the entry's current site.
    $user = $this->createRestrictedUser([
        'access cp',
        'access fr site',
        'access de site',
        'edit articles entries',
    ]);
    $this->loginUser($user);

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'source_site' => 'en',
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('returns 404 when entry has no localization in source_site', function () {
    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
    ]);

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles', site: 'en');

    $this->loginUser(); // super

    // Entry only exists in en; attempt to translate from fr.
    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'source_site' => 'fr',
        'target_sites' => ['en'],
    ]);

    $response->assertStatus(404)
        ->assertJsonPath('error.code', 'resource_not_found')
        ->assertJsonPath('error.message', "Entry [{$entry->id()}] has no localization in [fr].");
});

it('allows dispatch when user has access to all requested target sites', function () {
    Queue::fake();

    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    $this->createTestCollection('articles', ['en', 'fr', 'de']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $user = $this->createRestrictedUser([
        'access cp',
        'access en site',
        'access fr site',
        'access de site',
        'edit articles entries',
    ]);
    $this->loginUser($user);

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr', 'de'],
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    Queue::assertPushed(TranslateEntryJob::class, 2);
});

// ── Trigger: validation ────────────────────────────────────────────────────────

it('returns 422 when entry_id is missing', function () {
    $this->loginUser();

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['entry_id']);
});

it('returns 422 when target_sites is missing', function () {
    $this->loginUser();

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => 'some-id',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['target_sites']);
});

it('returns 422 when target_sites is not an array', function () {
    $this->loginUser();

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => 'some-id',
        'target_sites' => 'fr',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['target_sites']);
});

it('returns 422 when options.generate_slug is not a boolean', function () {
    $this->loginUser();

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr'],
        'options' => ['generate_slug' => 'not-a-bool'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['options.generate_slug']);
});

// ── Trigger: entry existence ───────────────────────────────────────────────────

it('returns 404 when the entry does not exist', function () {
    $this->loginUser();

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => 'nonexistent-entry-id',
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'resource_not_found',
                'message' => 'Entry [nonexistent-entry-id] not found.',
                'retryable' => false,
            ],
        ]);
});

it('returns a structured error envelope when dispatch throws a domain exception', function () {
    config()->set('queue.default', 'sync');

    app()->instance(TranslationService::class, new class implements TranslationService
    {
        public function translate(array $units, string $sourceLocale = 'en', string $targetLocale = 'fr'): array
        {
            throw new ProviderRateLimitedException('Provider temporarily rate limited the request.');
        }
    });

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(502)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'provider_rate_limited',
                'message' => 'Translation service rate limit exceeded. Please try again later.',
                'message_key' => 'magic-translator::messages.error_provider_rate_limited',
                'retryable' => true,
            ],
        ]);
});

// ── Trigger: job dispatch ─────────────────────────────────────────────────────

it('dispatches a TranslateEntryJob for each target site', function () {
    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    Queue::assertPushed(TranslateEntryJob::class, 1);
});

it('dispatches one job per target site', function () {
    // Add a third site so we can dispatch to two targets.
    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de'],
    ]);

    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr', 'de']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr', 'de'],
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    Queue::assertPushed(TranslateEntryJob::class, 2);
});

it('returns a job entry for every target site in the response', function () {
    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(200);

    $body = $response->json();

    expect($body['success'])->toBeTrue();
    expect($body['jobs'])->toHaveCount(1);
    expect($body['jobs'][0])->toHaveKeys(['id', 'target_site', 'status']);
    expect($body['jobs'][0]['target_site'])->toBe('fr');
    expect($body['jobs'][0]['status'])->toBe('pending');
    expect($body['jobs'][0]['id'])->toBeString()->not->toBeEmpty();
});

it('stores a pending cache entry for each dispatched job', function () {
    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr'],
    ]);

    $response->assertStatus(200);

    $jobId = $response->json('jobs.0.id');

    $cached = Cache::get("magic-translator:job:{$jobId}");

    expect($cached)->not->toBeNull();
    expect($cached['status'])->toBe('pending');
    expect($cached['target_site'])->toBe('fr');
});

it('passes source_site and options through to the dispatched job', function () {
    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $response = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'source_site' => 'en',
        'target_sites' => ['fr'],
        'options' => ['generate_slug' => true, 'overwrite' => false],
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);

    // Job is pushed — specific constructor args are verified via integration
    // in TranslateEntryJobTest; here we just confirm it was dispatched.
    Queue::assertPushed(TranslateEntryJob::class);
});

// ── Mark current endpoint ─────────────────────────────────────────────────────

it('marks an existing localization as current and persists updated metadata', function () {
    $this->loginUser();

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $entry = $this->createTestEntry('articles', ['title' => 'Hello source']);

    $localization = $entry->makeLocalization('fr');

    Statamic\Facades\Blink::put("magic-translator:translating:{$localization->id()}", true);

    try {
        $localization->data([
            'title' => 'Bonjour cible',
            'magic_translator' => ['custom' => 'keep-me'],
        ]);
        $localization->save();
    } finally {
        Statamic\Facades\Blink::forget("magic-translator:translating:{$localization->id()}");
    }

    $expectedSourceHash = sourceContentHashFor($entry);

    $response = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload($entry->id(), 'fr'));

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.handle', 'fr')
        ->assertJsonPath('meta.exists', true)
        ->assertJsonPath('meta.is_stale', false)
        ->assertJsonPath('meta.source_content_hash', $expectedSourceHash);

    $lastTranslatedAt = $response->json('meta.last_translated_at');

    expect($lastTranslatedAt)->toBeString()->not->toBeEmpty();

    $fresh = Statamic\Facades\Entry::find($entry->id())->in('fr');
    $meta = $fresh->get('magic_translator');

    expect($meta)->toBeArray();
    expect($meta['custom'])->toBe('keep-me');
    expect($meta['source_content_hash'])->toBe($expectedSourceHash);
    expect($meta['last_translated_at'])->toBe($lastTranslatedAt);
});

it('returns 404 when mark-current entry does not exist', function () {
    $this->loginUser();

    $response = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload('missing-entry', 'fr'));

    $response->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'resource_not_found')
        ->assertJsonPath('error.message', 'Entry [missing-entry] not found.')
        ->assertJsonPath('error.retryable', false);
});

it('returns 404 when mark-current localization does not exist for locale', function () {
    $this->loginUser();

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default');
    $entry = $this->createTestEntry('articles', site: 'en');

    $response = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload($entry->id(), 'fr'));

    $response->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'resource_not_found')
        ->assertJsonPath('error.message', "Entry [{$entry->id()}] has no localization in [fr].")
        ->assertJsonPath('error.retryable', false);
});

it('requires authentication to mark a localization as current', function () {
    $response = $this->postJson(cpUrl('magic-translator/mark-current'), [
        'entry_id' => 'some-id',
        'locale' => 'fr',
    ]);

    $response->assertStatus(401);
});

it('forbids mark-current when user cannot edit the entry', function () {
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $entry = $this->createTestEntry('articles', site: 'en');

    $user = $this->createRestrictedUser([
        'access cp',
        'access en site',
        'access fr site',
        // intentionally no "edit articles entries"
    ], 'mark-current-no-edit');

    $this->loginUser($user);

    $response = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload($entry->id(), 'fr'));

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Forbidden.')
        ->assertJsonPath('error.retryable', false);
});

it('forbids mark-current for target locales outside accessible sites', function () {
    Statamic\Facades\Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr'],
    ]);

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default');
    $entry = $this->createTestEntry('articles', site: 'en');

    $localization = $entry->makeLocalization('fr');
    $localization->set('title', 'Bonjour');
    $localization->save();

    $user = $this->createRestrictedUser([
        'access cp',
        'access en site',
        'edit articles entries',
    ], 'mark-current-en-only');

    $this->loginUser($user);

    $response = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload($entry->id(), 'fr'));

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Not authorized to mark [fr] as current.')
        ->assertJsonPath('error.retryable', false);
});

it('returns 422 for mark-current on an excluded blueprint', function () {
    config(['statamic.magic-translator.exclude_blueprints' => ['articles.default']]);

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default');

    $entry = $this->createTestEntry('articles', site: 'en');

    $localization = $entry->makeLocalization('fr');
    $localization->set('title', 'Bonjour');
    $localization->save();

    $response = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload($entry->id(), 'fr'));

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'unsupported')
        ->assertJsonPath('error.message', 'Mark current is not supported for excluded blueprint [articles.default].')
        ->assertJsonPath('error.retryable', false);
});

it('is idempotent when marking a localization current multiple times', function () {
    $this->loginUser();

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles', 'default', [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $entry = $this->createTestEntry('articles', ['title' => 'Stable source'], 'en', 'stable-source');

    $localization = $entry->makeLocalization('fr');
    $localization->set('title', 'Source stable');
    $localization->save();

    $first = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload($entry->id(), 'fr'));
    $second = $this->postJson(cpUrl('magic-translator/mark-current'), markCurrentPayload($entry->id(), 'fr'));

    $first->assertStatus(200);
    $second->assertStatus(200);

    $firstHash = $first->json('meta.source_content_hash');
    $secondHash = $second->json('meta.source_content_hash');

    expect($firstHash)->toBeString()->not->toBeEmpty();
    expect($secondHash)->toBe($firstHash);

    $freshMeta = Statamic\Facades\Entry::find($entry->id())->in('fr')->get('magic_translator');

    expect($freshMeta['source_content_hash'])->toBe($firstHash);
});

// ── Status endpoint ────────────────────────────────────────────────────────────

it('returns pending status for a freshly dispatched job', function () {
    Queue::fake();

    $this->loginUser();
    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $triggerResponse = $this->postJson(cpUrl('magic-translator/translate'), [
        'entry_id' => $entry->id(),
        'target_sites' => ['fr'],
    ]);

    $jobId = $triggerResponse->json('jobs.0.id');

    $statusResponse = $this->getJson(cpUrl('magic-translator/status').'?'.http_build_query(['jobs' => [$jobId]]));

    $statusResponse->assertStatus(200);

    $jobs = $statusResponse->json('jobs');
    expect($jobs)->toHaveCount(1);
    expect($jobs[0]['id'])->toBe($jobId);
    expect($jobs[0]['target_site'])->toBe('fr');
    expect($jobs[0]['status'])->toBe('pending');
});

it('returns completed status for a finished job', function () {
    $this->loginUser();

    $jobId = 'test-completed-job-id';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'completed',
        'error' => null,
    ], 600);

    $response = $this->getJson(cpUrl('magic-translator/status').'?'.http_build_query(['jobs' => [$jobId]]));

    $response->assertStatus(200);

    $jobs = $response->json('jobs');
    expect($jobs[0]['status'])->toBe('completed');
    expect($jobs[0]['target_site'])->toBe('fr');
    expect($jobs[0])->not->toHaveKey('error');
});

it('returns failed status with a structured error object for a failed job', function () {
    $this->loginUser();

    $jobId = 'test-failed-job-id';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'failed',
        'error' => [
            'code' => 'provider_unavailable',
            'message' => 'Translation service is temporarily unavailable. Please try again later.',
            'message_key' => 'magic-translator::messages.error_provider_unavailable',
            'retryable' => true,
        ],
    ], 600);

    $response = $this->getJson(cpUrl('magic-translator/status').'?'.http_build_query(['jobs' => [$jobId]]));

    $response->assertStatus(200);

    $jobs = $response->json('jobs');
    expect($jobs[0]['status'])->toBe('failed');
    expect($jobs[0]['error'])->toBe([
        'code' => 'provider_unavailable',
        'message' => 'Translation service is temporarily unavailable. Please try again later.',
        'message_key' => 'magic-translator::messages.error_provider_unavailable',
        'retryable' => true,
    ]);
});

it('wraps legacy string job errors into a structured error object', function () {
    $this->loginUser();

    $jobId = 'legacy-failed-job-id';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'failed',
        'error' => 'Translation API unavailable.',
    ], 600);

    $response = $this->getJson(cpUrl('magic-translator/status').'?'.http_build_query(['jobs' => [$jobId]]));

    $response->assertStatus(200);

    $jobs = $response->json('jobs');
    expect($jobs[0]['status'])->toBe('failed');
    expect($jobs[0]['error'])->toBe([
        'code' => 'unexpected_error',
        'message' => 'Translation API unavailable.',
        'retryable' => false,
    ]);
});

it('returns unknown status for an expired or invalid job id', function () {
    $this->loginUser();

    $response = $this->getJson(cpUrl('magic-translator/status').'?'.http_build_query(['jobs' => ['expired-job-id']]));

    $response->assertStatus(200);

    $jobs = $response->json('jobs');
    expect($jobs[0]['status'])->toBe('unknown');
    expect($jobs[0]['id'])->toBe('expired-job-id');
});

it('handles malformed cache payloads without failing', function () {
    $this->loginUser();

    $jobId = 'malformed-payload-job-id';

    // Simulate a stale/incomplete payload, e.g. after pending status expired
    // before the worker started and repopulated only the status field.
    Cache::put("magic-translator:job:{$jobId}", [
        'status' => 'running',
    ], 600);

    $response = $this->getJson(cpUrl('magic-translator/status').'?'.http_build_query(['jobs' => [$jobId]]));

    $response->assertStatus(200);

    $job = $response->json('jobs.0');
    expect($job['id'])->toBe($jobId);
    expect($job['target_site'])->toBeNull();
    expect($job['status'])->toBe('running');
});

it('returns statuses for multiple jobs in one request', function () {
    $this->loginUser();

    $completedId = 'job-completed';
    $failedId = 'job-failed';
    $unknownId = 'job-unknown';

    Cache::put("magic-translator:job:{$completedId}", [
        'id' => $completedId,
        'target_site' => 'fr',
        'status' => 'completed',
        'error' => null,
    ], 600);

    Cache::put("magic-translator:job:{$failedId}", [
        'id' => $failedId,
        'target_site' => 'de',
        'status' => 'failed',
        'error' => [
            'code' => 'unexpected_error',
            'message' => 'Something went wrong.',
            'retryable' => false,
        ],
    ], 600);

    $queryString = http_build_query(['jobs' => [$completedId, $failedId, $unknownId]]);
    $response = $this->getJson(cpUrl('magic-translator/status').'?'.$queryString);

    $response->assertStatus(200);

    $jobs = collect($response->json('jobs'))->keyBy('id');

    expect($jobs->get($completedId)['status'])->toBe('completed');
    expect($jobs->get($failedId)['status'])->toBe('failed');
    expect($jobs->get($failedId)['error'])->toBe([
        'code' => 'unexpected_error',
        'message' => 'Something went wrong.',
        'retryable' => false,
    ]);
    expect($jobs->get($unknownId)['status'])->toBe('unknown');
});

it('returns 422 when status request is missing jobs parameter', function () {
    $this->loginUser();

    $response = $this->getJson(cpUrl('magic-translator/status'));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['jobs']);
});

// ── Job cache integration ─────────────────────────────────────────────────────

it('job updates cache to running then completed when executed synchronously', function () {
    use_fake_translation_service_for_job_test();

    $jobId = 'sync-job-test';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'pending',
        'error' => null,
    ], 600);

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $job = new TranslateEntryJob($entry->id(), 'fr', null, [], $jobId);
    app()->call([$job, 'handle']);

    $cached = Cache::get("magic-translator:job:{$jobId}");
    expect($cached['status'])->toBe('completed');
});

it('clears stale cache errors when a retried job later succeeds', function () {
    use_fake_translation_service_for_job_test();

    $jobId = 'retry-success-job-id';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'failed',
        'error' => 'Previous transient error',
    ], 600);

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

    $job = new TranslateEntryJob($entry->id(), 'fr', null, [], $jobId);
    app()->call([$job, 'handle']);

    $cached = Cache::get("magic-translator:job:{$jobId}");
    expect($cached['status'])->toBe('completed');
    expect($cached['error'] ?? null)->toBeNull();
});

it('job updates cache to failed when translation throws', function () {
    // Bind a translation service that always throws.
    app()->instance(
        TranslationService::class,
        new class implements TranslationService
        {
            public function translate(array $units, string $sourceLocale = 'en', string $targetLocale = 'fr'): array
            {
                throw new RuntimeException('Simulated API error');
            }
        },
    );

    $jobId = 'fail-job-test';

    Cache::put("magic-translator:job:{$jobId}", [
        'id' => $jobId,
        'target_site' => 'fr',
        'status' => 'pending',
        'error' => null,
    ], 600);

    $this->createTestCollection('articles', ['en', 'fr']);
    $this->createTestBlueprint('articles');
    $entry = $this->createTestEntry('articles');

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

// ── Helpers (file-scope) ──────────────────────────────────────────────────────

function use_fake_translation_service_for_job_test(): void
{
    $mock = Mockery::mock(TranslationService::class);
    $mock->shouldReceive('translate')->andReturnUsing(
        fn (array $units) => array_map(
            fn (ElSchneider\MagicTranslator\Data\TranslationUnit $u) => $u->withTranslation('FR: '.$u->text),
            $units,
        ),
    );
    app()->instance(TranslationService::class, $mock);
}
