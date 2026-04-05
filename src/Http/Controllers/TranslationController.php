<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Http\Controllers;

use ElSchneider\MagicTranslator\Exceptions\MagicTranslatorException;
use ElSchneider\MagicTranslator\Jobs\TranslateEntryJob;
use ElSchneider\MagicTranslator\Support\AccessibleSites;
use ElSchneider\MagicTranslator\Support\BlueprintExclusions;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use ElSchneider\MagicTranslator\Support\SourceHashCache;
use ElSchneider\MagicTranslator\Support\TranslationLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Throwable;

/**
 * HTTP controller for the Magic Translator CP endpoints.
 *
 * Provides three CP endpoints:
 *  - translate     — dispatch translation jobs
 *  - mark-current  — mark locale metadata as current
 *  - status        — poll job statuses from cache
 */
final class TranslationController extends Controller
{
    /**
     * Cache TTL in seconds (60 minutes).
     */
    private const CACHE_TTL = 3600;

    /**
     * Cache key prefix for job status entries.
     */
    private const CACHE_PREFIX = 'magic-translator:job:';

    /**
     * Running jobs with older heartbeats are considered stale failures.
     */
    private const STALE_RUNNING_SECONDS = 600;

    /**
     * Validate the request, verify the entry and user, then dispatch one
     * TranslateEntryJob per target site. Each job's initial status is stored
     * in cache under a UUID key so the client can poll for progress.
     *
     * Returns JSON: { success: true, jobs: [{id, target_site, status}] }
     */
    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_id' => ['required', 'string'],
            'source_site' => ['nullable', 'string'],
            'target_sites' => ['required', 'array'],
            'target_sites.*' => ['required', 'string'],
            'options' => ['nullable', 'array'],
            'options.generate_slug' => ['nullable', 'boolean'],
            'options.overwrite' => ['nullable', 'boolean'],
        ]);

        // ── Find the entry ────────────────────────────────────────────────────
        $entry = Entry::find($validated['entry_id']);

        if ($entry === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => "Entry [{$validated['entry_id']}] not found.",
                    'retryable' => false,
                ],
            ], 404);
        }

        // ── Authorise the user ────────────────────────────────────────────────
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Unauthorized.',
                    'retryable' => false,
                ],
            ], 401);
        }

        if (! $user->can('edit', $entry)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Forbidden.',
                    'retryable' => false,
                ],
            ], 403);
        }

        // ── Enforce collection + per-site authorization ───────────────────────
        $sourceSite = $validated['source_site'] ?? $entry->locale();
        $targetSites = $validated['target_sites'];
        $options = $validated['options'] ?? [];

        $accessibleSites = AccessibleSites::forCollection($user, $entry->collection());

        if (! $accessibleSites->contains($sourceSite)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => "Not authorized to translate from [{$sourceSite}].",
                    'retryable' => false,
                ],
            ], 403);
        }

        // Entry must actually exist in the source locale.
        if ($entry->in($sourceSite) === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => "Entry [{$validated['entry_id']}] has no localization in [{$sourceSite}].",
                    'retryable' => false,
                ],
            ], 404);
        }

        $forbidden = array_values(array_diff(
            $targetSites,
            $accessibleSites->reject(fn ($handle) => $handle === $sourceSite)->all(),
        ));

        if ($forbidden !== []) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Not authorized to translate into: '.implode(', ', $forbidden).'.',
                    'retryable' => false,
                ],
            ], 403);
        }

        $jobs = [];
        $currentTargetSite = null;

        try {
            foreach ($targetSites as $targetSite) {
                $currentTargetSite = $targetSite;
                $jobId = (string) Str::uuid();

                // Store initial status in cache so the status endpoint can return
                // something meaningful even before the job starts.
                Cache::put(
                    self::CACHE_PREFIX.$jobId,
                    [
                        'id' => $jobId,
                        'target_site' => $targetSite,
                        'status' => 'pending',
                        'heartbeat_at' => now()->toIso8601String(),
                        'error' => null,
                    ],
                    self::CACHE_TTL,
                );

                TranslateEntryJob::dispatch(
                    $validated['entry_id'],
                    $targetSite,
                    $sourceSite,
                    $options,
                    $jobId,
                );

                $jobs[] = [
                    'id' => $jobId,
                    'target_site' => $targetSite,
                    'status' => 'pending',
                ];
            }
        } catch (MagicTranslatorException $exception) {
            TranslationLogger::error($exception, $this->requestLogContext($request, $validated, $currentTargetSite));

            return response()->json([
                'success' => false,
                'error' => $exception->toApiError(),
            ], $exception->httpStatus());
        } catch (Throwable $exception) {
            TranslationLogger::unexpected($exception, $this->requestLogContext($request, $validated, $currentTargetSite));

            return response()->json([
                'success' => false,
                'error' => $this->unexpectedApiError(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'jobs' => $jobs,
        ]);
    }

    /**
     * Mark a target localization as current relative to its source content.
     *
     * No translation is executed; only `magic_translator` metadata is updated
     * with a fresh timestamp and current source content hash.
     */
    public function markCurrent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_id' => ['required', 'string'],
            'locale' => ['required', 'string'],
        ]);

        $entry = Entry::find($validated['entry_id']);

        if ($entry === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => "Entry [{$validated['entry_id']}] not found.",
                    'retryable' => false,
                ],
            ], 404);
        }

        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Unauthorized.',
                    'retryable' => false,
                ],
            ], 401);
        }

        if (! $user->can('edit', $entry)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Forbidden.',
                    'retryable' => false,
                ],
            ], 403);
        }

        $locale = $validated['locale'];
        $localization = $entry->in($locale);

        if ($localization === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => "Entry [{$validated['entry_id']}] has no localization in [{$locale}].",
                    'retryable' => false,
                ],
            ], 404);
        }

        $accessibleSites = AccessibleSites::forCollection($user, $entry->collection());

        if (! $accessibleSites->contains($locale)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => "Not authorized to mark [{$locale}] as current.",
                    'retryable' => false,
                ],
            ], 403);
        }

        $collectionHandle = $entry->collectionHandle();
        $blueprintHandle = $entry->blueprint()->handle();

        if (BlueprintExclusions::contains($collectionHandle, $blueprintHandle)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unsupported',
                    'message' => "Mark current is not supported for excluded blueprint [{$collectionHandle}.{$blueprintHandle}].",
                    'retryable' => false,
                ],
            ], 422);
        }

        $root = $entry->isRoot() ? $entry : $entry->root();

        if ($root === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => "Entry [{$validated['entry_id']}] source not found.",
                    'retryable' => false,
                ],
            ], 404);
        }

        $fieldDefs = FieldDefinitionBuilder::fromBlueprint($root->blueprint());
        $sourceHash = app(SourceHashCache::class)->get($root, $fieldDefs);
        $translatedAt = now()->toIso8601String();

        $meta = $localization->get('magic_translator') ?? [];

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['last_translated_at'] = $translatedAt;
        $meta['source_content_hash'] = $sourceHash;
        $localization->set('magic_translator', $meta);

        Blink::put("magic-translator:translating:{$localization->id()}", true);

        try {
            $localization->save();
        } finally {
            Blink::forget("magic-translator:translating:{$localization->id()}");
        }

        return response()->json([
            'success' => true,
            'meta' => [
                'handle' => $locale,
                'exists' => true,
                'last_translated_at' => $translatedAt,
                'source_content_hash' => $sourceHash,
                'is_stale' => false,
            ],
        ]);
    }

    /**
     * Read the status of one or more jobs from cache.
     *
     * Accepts: ?jobs[]  — array of job IDs (query-string or JSON body)
     * Returns JSON: { jobs: [{id, target_site, status, error?}] }
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'jobs' => ['required', 'array'],
            'jobs.*' => ['required', 'string'],
        ]);

        $jobIds = $request->input('jobs', []);

        $jobs = array_map(function (string $jobId): array {
            $data = Cache::get(self::CACHE_PREFIX.$jobId);

            if (! is_array($data) || ! isset($data['status'])) {
                // Cache expired, unknown ID, or malformed payload.
                return [
                    'id' => $jobId,
                    'target_site' => null,
                    'status' => 'failed',
                    'error' => [
                        'code' => 'job_expired',
                        'message' => 'Translation job status expired.',
                        'retryable' => false,
                    ],
                ];
            }

            $result = [
                'id' => is_string($data['id'] ?? null) ? $data['id'] : $jobId,
                'target_site' => is_string($data['target_site'] ?? null) ? $data['target_site'] : null,
                'status' => is_string($data['status']) ? $data['status'] : 'failed',
            ];

            if ($result['status'] === 'running') {
                $heartbeat = null;

                if (is_string($data['heartbeat_at'] ?? null)) {
                    try {
                        $heartbeat = Carbon::parse($data['heartbeat_at']);
                    } catch (Throwable) {
                        $heartbeat = null;
                    }
                }

                if ($heartbeat === null || $heartbeat->lt(now()->subSeconds(self::STALE_RUNNING_SECONDS))) {
                    return [
                        'id' => $result['id'],
                        'target_site' => $result['target_site'],
                        'status' => 'failed',
                        'error' => [
                            'code' => 'job_stale',
                            'message' => 'Translation job is no longer responding.',
                            'retryable' => false,
                        ],
                    ];
                }
            }

            if ($result['status'] === 'failed') {
                if (is_array($data['error'] ?? null)) {
                    $result['error'] = $data['error'];
                } elseif (is_string($data['error'] ?? null) && $data['error'] !== '') {
                    $result['error'] = [
                        'code' => 'unexpected_error',
                        'message' => $data['error'],
                        'retryable' => false,
                    ];
                }
            }

            return $result;
        }, $jobIds);

        return response()->json(['jobs' => $jobs]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function requestLogContext(Request $request, array $validated, ?string $targetSite = null): array
    {
        return array_filter([
            'entry_id' => $validated['entry_id'] ?? null,
            'source_site' => $validated['source_site'] ?? null,
            'target_sites' => is_array($validated['target_sites'] ?? null) ? $validated['target_sites'] : null,
            'target_site' => $targetSite,
            'user_id' => $request->user()?->id(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{code: string, message: string, retryable: bool}
     */
    private function unexpectedApiError(): array
    {
        return [
            'code' => 'unexpected_error',
            'message' => (string) __('magic-translator::messages.error_unexpected'),
            'retryable' => false,
        ];
    }
}
