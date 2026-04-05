<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

final readonly class PlanItem
{
    public function __construct(
        public string $entryId,
        public string $entryTitle,
        public string $collection,
        public string $sourceSite,
        public string $targetSite,
        public PlanAction $action,
        public string $reason,
    ) {}

    public function willProcess(): bool
    {
        return $this->action->willProcess();
    }
}
