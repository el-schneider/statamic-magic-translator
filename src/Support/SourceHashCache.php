<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Support;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;

final class SourceHashCache
{
    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function get(EntryContract $entry, array $fields): string
    {
        $lastModifiedTimestamp = $entry->lastModified()?->timestamp ?? 0;
        $key = "magic-translator:src-hash:{$entry->id()}:{$lastModifiedTimestamp}";

        $cached = Blink::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $hash = ContentFingerprint::compute($entry->data()->all(), $fields);
        Blink::put($key, $hash);

        return $hash;
    }
}
