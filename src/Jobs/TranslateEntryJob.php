<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Jobs;

use ElSchneider\ContentTranslator\Actions\TranslateEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Statamic\Facades\Entry as EntryFacade;

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
 */
final class TranslateEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     */
    public function handle(TranslateEntry $action): void
    {
        if (EntryFacade::find($this->entryId) === null) {
            return;
        }

        try {
            $action->handle(
                $this->entryId,
                $this->targetSite,
                $this->sourceSite,
                $this->options,
            );
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === "Entry [{$this->entryId}] not found.") {
                return;
            }

            throw $e;
        }
    }
}
