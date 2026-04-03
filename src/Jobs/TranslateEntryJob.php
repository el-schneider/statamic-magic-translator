<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Jobs;

use ElSchneider\ContentTranslator\Actions\TranslateEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
     */
    public function handle(TranslateEntry $action): void
    {
        $action->handle(
            $this->entryId,
            $this->targetSite,
            $this->sourceSite,
            $this->options,
        );
    }
}
