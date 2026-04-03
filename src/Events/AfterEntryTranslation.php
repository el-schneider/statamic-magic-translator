<?php

declare(strict_types=1);

namespace ElSchneider\StatamicContentTranslator\Events;

use Statamic\Entries\Entry;

final class AfterEntryTranslation
{
    public function __construct(
        public readonly Entry $entry,
        public readonly string $targetSite,
        public array $translatedData,  // mutable — listeners can modify before save
    ) {}
}
