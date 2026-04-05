<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Support;

use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Collection;
use Statamic\Facades\Site;

/**
 * Compute which sites a user may access for translation operations.
 *
 * Mirrors Statamic's own CP pattern in
 * `EntriesController::getAuthorizedSitesForCollection()`:
 *
 *     $collection
 *         ->sites()
 *         ->filter(fn ($handle) => $user->can('view', Site::get($handle)))
 *
 * We additionally gate the whole result on the user having
 * `edit {collection} entries` permission for the collection, since a user
 * without that permission cannot meaningfully receive a translation even
 * if they have site access.
 */
final class AccessibleSites
{
    /**
     * Site handles the user may access for editing entries in this
     * collection. Empty when multi-site is disabled, the user lacks
     * `edit {collection} entries`, or no configured collection site is
     * accessible.
     *
     * @return LaravelCollection<int, string>
     */
    public static function forCollection(User $user, Collection $collection): LaravelCollection
    {
        if (! Site::multiEnabled()) {
            return collect();
        }

        if (! $user->hasPermission("edit {$collection->handle()} entries") && ! $user->isSuper()) {
            return collect();
        }

        return $collection
            ->sites()
            ->filter(fn (string $handle): bool => $user->can('view', Site::get($handle)))
            ->values();
    }

    /**
     * Site handles the user may translate INTO for this collection,
     * excluding the source locale.
     *
     * @return LaravelCollection<int, string>
     */
    public static function forTranslationTargets(
        User $user,
        Collection $collection,
        ?string $excludeSource = null,
    ): LaravelCollection {
        $handles = self::forCollection($user, $collection);

        if ($excludeSource === null) {
            return $handles;
        }

        return $handles
            ->reject(fn (string $handle): bool => $handle === $excludeSource)
            ->values();
    }
}
