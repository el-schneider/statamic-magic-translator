<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\StatamicActions;

use ElSchneider\ContentTranslator\Support\BlueprintExclusions;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;

final class TranslateEntryAction extends Action
{
    protected $confirm = false;

    public static function title(): string
    {
        return __('content-translator::messages.translate_action');
    }

    public function run($items, $values): array
    {
        $sites = Site::all()->map(fn ($site) => [
            'handle' => $site->handle(),
            'name' => $site->name(),
        ])->values()->all();

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

        return ! BlueprintExclusions::contains($item->collectionHandle(), $item->blueprint()->handle());
    }

    public function authorize($user, $item): bool
    {
        return $user->can('edit', $item);
    }
}
