<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Events;

use Statamic\Entries\Entry;

final class BeforeEntryTranslation
{
    public function __construct(
        public readonly Entry $entry,
        public readonly string $targetSite,
        public array $units,  // mutable — listeners can modify
    ) {}
}
