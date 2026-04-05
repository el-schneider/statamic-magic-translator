<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Console;

use ElSchneider\ContentTranslator\Support\BlueprintExclusions;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

final class TranslationPlanner
{
    /**
     * Build a translation plan from filter criteria.
     */
    public function plan(FilterCriteria $filters): TranslationPlan
    {
        $this->assertKnownHandles($filters);

        $items = [];

        foreach ($this->resolveEntries($filters) as $entry) {
            foreach ($this->resolveTargetSites($entry, $filters) as $targetSite) {
                $items[] = $this->classify($entry, $targetSite, $filters);
            }
        }

        return new TranslationPlan($items);
    }

    /**
     * @return iterable<EntryContract>
     */
    private function resolveEntries(FilterCriteria $filters): iterable
    {
        if ($filters->entryIds !== []) {
            foreach ($filters->entryIds as $id) {
                $entry = EntryFacade::find($id);

                if ($entry === null) {
                    continue;
                }

                if (! $this->passesBlueprintFilters($entry, $filters)) {
                    continue;
                }

                yield $entry;
            }

            return;
        }

        $collections = $filters->collections !== []
            ? $filters->collections
            : CollectionFacade::handles()->all();

        $seen = [];

        foreach ($collections as $collectionHandle) {
            foreach (EntryFacade::query()->where('collection', $collectionHandle)->get() as $entry) {
                $root = $entry->hasOrigin() ? $entry->root() : $entry;

                if (isset($seen[$root->id()])) {
                    continue;
                }

                $seen[$root->id()] = true;

                if (! $this->passesBlueprintFilters($root, $filters)) {
                    continue;
                }

                yield $root;
            }
        }
    }

    private function passesBlueprintFilters(EntryContract $entry, FilterCriteria $filters): bool
    {
        $collectionHandle = $entry->collectionHandle();
        $blueprintHandle = $entry->blueprint()->handle();

        if (BlueprintExclusions::contains($collectionHandle, $blueprintHandle)) {
            return false;
        }

        if ($filters->blueprints !== [] && ! in_array($blueprintHandle, $filters->blueprints, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function resolveTargetSites(EntryContract $entry, FilterCriteria $filters): array
    {
        $collectionSites = $entry->collection()->sites()->all();
        $source = $this->resolveSourceSite($entry, $filters);

        if ($filters->targetSites !== []) {
            return array_values(array_intersect($filters->targetSites, $collectionSites));
        }

        return array_values(array_filter(
            $collectionSites,
            static fn (string $site): bool => $site !== $source,
        ));
    }

    private function resolveSourceSite(EntryContract $entry, FilterCriteria $filters): string
    {
        if ($filters->sourceSite !== null) {
            return $filters->sourceSite;
        }

        $root = $entry->hasOrigin() ? $entry->root() : $entry;

        return $root->locale();
    }

    private function classify(EntryContract $entry, string $targetSite, FilterCriteria $filters): PlanItem
    {
        $source = $this->resolveSourceSite($entry, $filters);
        $title = (string) ($entry->get('title') ?? $entry->id());

        return new PlanItem(
            entryId: $entry->id(),
            entryTitle: $title,
            collection: $entry->collectionHandle(),
            sourceSite: $source,
            targetSite: $targetSite,
            action: PlanAction::Translate,
            reason: 'target localization missing',
        );
    }

    private function assertKnownHandles(FilterCriteria $filters): void {}
}
