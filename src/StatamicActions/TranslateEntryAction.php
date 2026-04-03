<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\StatamicActions;

use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;

final class TranslateEntryAction extends Action
{
    public static function title(): string
    {
        return __('content-translator::messages.translate_action');
    }

    public function run($items, $values): array
    {
        return [
            'callback' => ['openTranslationDialog', $items->map->id()->values()->all()],
        ];
    }

    public function visibleTo($item): bool
    {
        if (! $item instanceof Entry) {
            return false;
        }

        $configuredCollections = (array) config('content-translator.collections', []);

        if (! in_array($item->collectionHandle(), $configuredCollections, strict: true)) {
            return false;
        }

        return Site::all()->count() > 1;
    }

    public function authorize($user, $item): bool
    {
        return $user->can('edit', $item);
    }
}
