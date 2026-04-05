<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\StatamicActions;

use ElSchneider\ContentTranslator\Support\AccessibleSites;
use ElSchneider\ContentTranslator\Support\BlueprintExclusions;
use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Actions\Action;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User as UserFacade;

final class TranslateEntryAction extends Action
{
    protected $confirm = false;

    public static function title(): string
    {
        return __('content-translator::messages.translate_action');
    }

    public function run($items, $values): array
    {
        $user = UserFacade::current();

        // Intersect accessible sites across all selected items' collections.
        // The dialog lets the user pick the source locale, so we pass the full
        // accessible set (including each item's locale) and let the client
        // strip the chosen source from the target list.
        $allowedHandles = $user === null
            ? collect()
            : $this->intersectAccessibleSites($user, $items);

        $sites = Site::all()
            ->filter(fn ($site) => $allowedHandles->contains($site->handle()))
            ->map(fn ($site) => [
                'handle' => $site->handle(),
                'name' => $site->name(),
            ])
            ->values()
            ->all();

        return [
            'callback' => [
                'openTranslationDialog',
                $items->map->id()->values()->all(),
                $sites,
            ],
        ];
    }

    public function visibleTo($item): bool
    {
        if (! $item instanceof Entry) {
            return false;
        }

        if (Site::all()->count() <= 1) {
            return false;
        }

        if (BlueprintExclusions::contains($item->collectionHandle(), $item->blueprint()->handle())) {
            return false;
        }

        $user = UserFacade::current();

        if ($user === null) {
            return false;
        }

        // Hide the action when the user has no accessible target locales for
        // this entry's collection (excluding the entry's own locale).
        return AccessibleSites::forTranslationTargets($user, $item->collection(), $item->locale())->isNotEmpty();
    }

    public function authorize($user, $item): bool
    {
        if (! $item instanceof Entry) {
            return false;
        }

        if (! $user->can('edit', $item)) {
            return false;
        }

        // Must have at least one accessible target other than the source.
        return AccessibleSites::forTranslationTargets($user, $item->collection(), $item->locale())->isNotEmpty();
    }

    /**
     * @param  LaravelCollection<int, Entry>  $items
     * @return LaravelCollection<int, string>
     */
    private function intersectAccessibleSites(User $user, LaravelCollection $items): LaravelCollection
    {
        return $items
            ->map(fn (Entry $entry) => AccessibleSites::forCollection($user, $entry->collection()))
            ->reduce(
                fn (?LaravelCollection $carry, LaravelCollection $handles) => $carry === null
                    ? $handles
                    : $carry->intersect($handles)->values(),
            ) ?? collect();
    }
}
