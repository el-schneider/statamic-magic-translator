<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Jobs;

use ElSchneider\ContentTranslator\Actions\TranslateEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Statamic\Facades\Entry as EntryFacade;
use Throwable;

/**
 * Thin job wrapper around the TranslateEntry action.
 *
 * Dispatches one job per entry-locale pair. The actual translation
 * orchestration is delegated to TranslateEntry so that it can also
 * be called directly (e.g. in tests or from controllers) without
 * going through the queue.
 *
 * Queue settings are picked up from config/content-translator.php.
 * Retries use exponential backoff: 30s → 60s → 120s.
 *
 * When a $jobId is provided the job writes status updates to cache
 * (key: content-translator:job:{jobId}) so the HTTP status endpoint
 * can poll for progress without requiring a database.
 */
final class TranslateEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cache key prefix shared with TranslationController.
     */
    private const CACHE_PREFIX = 'content-translator:job:';

    /**
     * Cache TTL in seconds (10 minutes) — matches TranslationController.
     */
    private const CACHE_TTL = 600;

    /**
     * Maximum number of attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * Backoff durations (seconds) between retry attempts.
     *
     * @var int[]
     */
    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly string $entryId,
        private readonly string $targetSite,
        private readonly ?string $sourceSite = null,
        private readonly array $options = [],
        private readonly ?string $jobId = null,
    ) {
        $queueConfig = config('content-translator.queue', []);

        if (! empty($queueConfig['connection'])) {
            $this->onConnection($queueConfig['connection']);
        }

        if (! empty($queueConfig['name'])) {
            $this->onQueue($queueConfig['name']);
        }
    }

    /**
     * Execute the job via the TranslateEntry action.
     *
     * If the entry was deleted after dispatch but before execution, treat this
     * as a no-op so the job doesn't fail/retry pointlessly.
     *
     * Cache status lifecycle (only when $jobId is set):
     *   pending → running → completed | failed
     */
    public function handle(TranslateEntry $action): void
    {
        $this->updateCacheStatus('running');

        if (EntryFacade::find($this->entryId) === null) {
            // Entry was deleted — treat as successful no-op.
            $this->updateCacheStatus('completed');

            return;
        }

        try {
            $action->handle(
                $this->entryId,
                $this->targetSite,
                $this->sourceSite,
                $this->options,
            );

            $this->updateCacheStatus('completed');
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === "Entry [{$this->entryId}] not found.") {
                // Race condition: entry disappeared between the null-check above
                // and the action's own lookup. Treat as no-op.
                $this->updateCacheStatus('completed');

                return;
            }

            $this->updateCacheStatus('failed', $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            $this->updateCacheStatus('failed', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Called by Laravel after all retry attempts have been exhausted.
     * Ensures the cache entry reflects the terminal failed state even if a
     * retry cycle reset the status to 'running' before the final failure.
     */
    public function failed(Throwable $exception): void
    {
        $this->updateCacheStatus('failed', $exception->getMessage());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Merge a status (and optional error) into the existing cache entry for
     * this job. Does nothing when no $jobId was provided (backwards compat).
     */
    private function updateCacheStatus(string $status, ?string $error = null): void
    {
        if ($this->jobId === null) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX.$this->jobId;

        /** @var array<string, mixed> $existing */
        $existing = Cache::get($cacheKey, []);

        $data = array_merge($existing, ['status' => $status]);

        if ($error !== null) {
            $data['error'] = $error;
        }

        Cache::put($cacheKey, $data, self::CACHE_TTL);
    }
}
