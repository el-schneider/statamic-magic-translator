<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Console;

use ElSchneider\MagicTranslator\Support\BlueprintExclusions;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Throwable;

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
            return $filters->targetSites;
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
        $collection = $entry->collectionHandle();

        if (! in_array($targetSite, $entry->collection()->sites()->all(), true)) {
            return $this->item(
                entry: $entry,
                source: $source,
                targetSite: $targetSite,
                title: $title,
                collection: $collection,
                action: PlanAction::SkipUnsupported,
                reason: "collection does not support site {$targetSite}",
            );
        }

        $target = $entry->in($targetSite);

        if ($target === null) {
            return $this->item(
                entry: $entry,
                source: $source,
                targetSite: $targetSite,
                title: $title,
                collection: $collection,
                action: PlanAction::Translate,
                reason: 'target localization missing',
            );
        }

        if ($filters->overwrite) {
            return $this->item(
                entry: $entry,
                source: $source,
                targetSite: $targetSite,
                title: $title,
                collection: $collection,
                action: PlanAction::Overwrite,
                reason: '--overwrite set',
            );
        }

        if ($filters->includeStale && $this->isStale($entry, $target)) {
            return $this->item(
                entry: $entry,
                source: $source,
                targetSite: $targetSite,
                title: $title,
                collection: $collection,
                action: PlanAction::Stale,
                reason: 'source updated after last translation',
            );
        }

        return $this->item(
            entry: $entry,
            source: $source,
            targetSite: $targetSite,
            title: $title,
            collection: $collection,
            action: PlanAction::SkipExists,
            reason: 'target localization already exists',
        );
    }

    private function item(
        EntryContract $entry,
        string $source,
        string $targetSite,
        string $title,
        string $collection,
        PlanAction $action,
        string $reason,
    ): PlanItem {
        return new PlanItem(
            entryId: $entry->id(),
            entryTitle: $title,
            collection: $collection,
            sourceSite: $source,
            targetSite: $targetSite,
            action: $action,
            reason: $reason,
        );
    }

    private function isStale(EntryContract $sourceEntry, EntryContract $targetEntry): bool
    {
        $meta = $targetEntry->get('magic_translator');

        if (! is_array($meta) || ! isset($meta['last_translated_at'])) {
            return true;
        }

        try {
            $lastTranslatedAt = Carbon::parse($meta['last_translated_at']);
        } catch (Throwable) {
            return true;
        }

        $sourceModifiedAt = $sourceEntry->lastModified();

        return $sourceModifiedAt !== null && $sourceModifiedAt->greaterThan($lastTranslatedAt);
    }

    private function assertKnownHandles(FilterCriteria $filters): void
    {
        $knownSites = Site::all()->map->handle()->all();

        foreach ($filters->targetSites as $site) {
            if (! in_array($site, $knownSites, true)) {
                throw new InvalidArgumentException("Unknown site '{$site}'");
            }
        }

        if ($filters->sourceSite !== null && ! in_array($filters->sourceSite, $knownSites, true)) {
            throw new InvalidArgumentException("Unknown site '{$filters->sourceSite}'");
        }

        $knownCollections = CollectionFacade::handles()->all();

        foreach ($filters->collections as $collection) {
            if (! in_array($collection, $knownCollections, true)) {
                throw new InvalidArgumentException("Unknown collection '{$collection}'");
            }
        }
    }
}
