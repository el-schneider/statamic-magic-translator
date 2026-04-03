<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Fieldtypes;

use Illuminate\Support\Carbon;
use Statamic\Facades\Site;
use Statamic\Fields\Fieldtype;

/**
 * ContentTranslatorFieldtype
 *
 * A special, auto-injected fieldtype that surfaces localization status data
 * to the Vue component rendered in the entry's sidebar.
 *
 * This fieldtype is never manually added by editors — it is injected via the
 * EntryBlueprintFound listener in ServiceProvider when the entry belongs to a
 * configured collection.
 *
 * The `preload()` method is the main integration point: it collects per-site
 * localization metadata (existence, last_translated_at, staleness) and returns
 * it as an array that Statamic forwards to the Vue component as its initial
 * `meta` prop.
 */
final class ContentTranslatorFieldtype extends Fieldtype
{
    /**
     * Prevent editors from manually adding this fieldtype via the blueprint
     * editor UI. It is always injected programmatically.
     */
    protected $selectable = false;

    /**
     * Group the fieldtype under "special" in the fieldtype registry (consistent
     * with Statamic's built-in Spacer fieldtype).
     *
     * @var string[]
     */
    protected $categories = ['special'];

    /**
     * Pass data through unchanged on load (no transformation needed).
     *
     * @param  mixed  $data
     * @return mixed
     */
    public function preProcess($data)
    {
        return $data;
    }

    /**
     * Pass data through unchanged on save (no transformation needed).
     *
     * @param  mixed  $data
     * @return mixed
     */
    public function process($data)
    {
        return $data;
    }

    /**
     * Build the initial meta payload that the Vue component receives.
     *
     * When called from the blueprint editor (no entry context), a minimal
     * structure with empty/null values is returned so the component renders
     * gracefully without a live entry.
     *
     * Otherwise the method:
     *  1. Identifies the current site and whether the entry is the origin.
     *  2. Determines the origin's last-modified timestamp for staleness checks.
     *  3. Iterates all configured sites and, for each:
     *     - Checks whether a localization exists.
     *     - Reads the `last_translated_at` ISO-8601 string from the
     *       `content_translator` metadata array stored on the localization.
     *     - Marks the localization as stale when the origin was modified after
     *       the last translation.
     *
     * @return array{
     *     current_site: string|null,
     *     is_origin: bool,
     *     sites: array<int, array{
     *         handle: string,
     *         name: string,
     *         exists: bool,
     *         last_translated_at: string|null,
     *         is_stale: bool,
     *     }>
     * }
     */
    public function preload(): array
    {
        $entry = $this->field()?->parent();

        // Blueprint editor preview — no live entry available.
        if ($entry === null) {
            return [
                'current_site' => null,
                'is_origin' => false,
                'sites' => Site::all()->map(fn ($site) => [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'exists' => false,
                    'last_translated_at' => null,
                    'is_stale' => false,
                ])->values()->all(),
            ];
        }

        $currentSite = $entry->locale();
        $isOrigin = $entry->isRoot();

        // Always use the root entry for localization traversal and staleness.
        $rootEntry = $isOrigin ? $entry : $entry->root();

        // Determine the origin's last-modified time for staleness comparisons.
        $originUpdatedAt = null;

        if ($rootEntry !== null && method_exists($rootEntry, 'lastModified')) {
            $originUpdatedAt = $rootEntry->lastModified();
        }

        $sitesData = Site::all()->map(function ($site) use ($entry, $originUpdatedAt) {
            $siteHandle = $site->handle();
            $localization = $entry->in($siteHandle);
            $exists = $localization !== null;
            $lastTranslatedAt = null;
            $isStale = false;

            if ($localization !== null) {
                $meta = $localization->get('content_translator');

                if (is_array($meta) && isset($meta['last_translated_at'])) {
                    $lastTranslatedAt = $meta['last_translated_at'];

                    // A localization is stale when the origin was modified
                    // after the translation was last run.
                    if ($originUpdatedAt !== null) {
                        $translatedAt = Carbon::parse($lastTranslatedAt);
                        $isStale = $originUpdatedAt->greaterThan($translatedAt);
                    }
                }
            }

            return [
                'handle' => $siteHandle,
                'name' => $site->name(),
                'exists' => $exists,
                'last_translated_at' => $lastTranslatedAt,
                'is_stale' => $isStale,
            ];
        })->values()->all();

        return [
            'current_site' => $currentSite,
            'is_origin' => $isOrigin,
            'sites' => $sitesData,
        ];
    }

    /**
     * No configurable options — the fieldtype has a fixed, auto-injected
     * configuration.
     *
     * @return array<string, mixed>
     */
    protected function configFieldItems(): array
    {
        return [];
    }
}
