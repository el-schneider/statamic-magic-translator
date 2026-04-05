<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

/**
 * Immutable collection of PlanItems with summary helpers.
 */
final readonly class TranslationPlan
{
    /**
     * @param  PlanItem[]  $items
     */
    public function __construct(public array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Return a keyed array mapping action value → count.
     *
     * @return array<string, int>
     */
    public function countByAction(): array
    {
        $counts = [];
        foreach ($this->items as $item) {
            $key = $item->action->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Return only items that will actually be processed (translate/stale/overwrite).
     *
     * @return PlanItem[]
     */
    public function processable(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (PlanItem $i): bool => $i->willProcess(),
        ));
    }

    /**
     * @return string[]
     */
    public function collections(): array
    {
        return array_values(array_unique(array_map(
            static fn (PlanItem $i): string => $i->collection,
            $this->items,
        )));
    }

    /**
     * @return string[]
     */
    public function targetSites(): array
    {
        return array_values(array_unique(array_map(
            static fn (PlanItem $i): string => $i->targetSite,
            $this->items,
        )));
    }
}
