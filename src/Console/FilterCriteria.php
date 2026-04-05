<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

/**
 * Immutable input to TranslationPlanner.
 *
 * All array fields use string[] of handles/IDs. Empty arrays mean "no filter applied
 * on this axis" — e.g. empty $collections means "all collections".
 */
final readonly class FilterCriteria
{
    /**
     * @param  string[]  $targetSites  Empty → resolve per-entry from its collection sites
     * @param  string[]  $collections  Empty → all collections
     * @param  string[]  $entryIds  Empty → no entry-ID narrowing
     * @param  string[]  $blueprints  Empty → no blueprint narrowing
     */
    public function __construct(
        public array $targetSites,
        public ?string $sourceSite,
        public array $collections,
        public array $entryIds,
        public array $blueprints,
        public bool $includeStale,
        public bool $overwrite,
    ) {}

    public function hasAnySelectorFilter(): bool
    {
        return $this->targetSites !== []
            || $this->collections !== []
            || $this->entryIds !== []
            || $this->blueprints !== [];
    }
}
