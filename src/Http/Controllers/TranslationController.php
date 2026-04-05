<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Http\Controllers;

use ElSchneider\ContentTranslator\Exceptions\ContentTranslatorException;
use ElSchneider\ContentTranslator\Jobs\TranslateEntryJob;
use ElSchneider\ContentTranslator\Support\TranslationLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Throwable;

/**
 * HTTP controller for the Content Translator CP endpoints.
 *
 * Provides two endpoints:
 *  - POST /cp/content-translator/translate  — dispatch translation jobs
 *  - GET  /cp/content-translator/status     — poll job statuses from cache
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
    private const CACHE_PREFIX = 'content-translator:job:';

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

        // ── Dispatch one job per target site ──────────────────────────────────
        $sourceSite = $validated['source_site'] ?? null;
        $targetSites = $validated['target_sites'];
        $options = $validated['options'] ?? [];

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
        } catch (ContentTranslatorException $exception) {
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
                    'status' => 'unknown',
                ];
            }

            $result = [
                'id' => is_string($data['id'] ?? null) ? $data['id'] : $jobId,
                'target_site' => is_string($data['target_site'] ?? null) ? $data['target_site'] : null,
                'status' => is_string($data['status']) ? $data['status'] : 'unknown',
            ];

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
            'message' => (string) __('content-translator::messages.error_unexpected'),
            'retryable' => false,
        ];
    }
}
