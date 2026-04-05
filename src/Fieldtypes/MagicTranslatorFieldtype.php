<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Fieldtypes;

use ElSchneider\MagicTranslator\Support\AccessibleSites;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use ElSchneider\MagicTranslator\Support\SourceHashCache;
use Illuminate\Support\Carbon;
use Statamic\Facades\Blink;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Fields\Fieldtype;
use Throwable;

/**
 * MagicTranslatorFieldtype
 *
 * A special, auto-injected fieldtype that surfaces localization status data
 * to the Vue component rendered in the entry's sidebar.
 *
 * This fieldtype is never manually added by editors — it is injected via the
 * EntryBlueprintFound listener in ServiceProvider unless the blueprint is
 * excluded by configuration.
 *
 * The `preload()` method is the main integration point: it collects per-site
 * localization metadata (existence, last_translated_at, staleness) and returns
 * it as an array that Statamic forwards to the Vue component as its initial
 * `meta` prop.
 */
final class MagicTranslatorFieldtype extends Fieldtype
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
     *       `magic_translator` metadata array stored on the localization.
     *     - Marks the localization as stale when the origin was modified after
     *       the last translation.
     *
     * @return array{
     *     current_site: string|null,
     *     origin_site: string|null,
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
                'entry_id' => null,
                'current_site' => null,
                'origin_site' => null,
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

        // Fallback source when localized saves drop computed values.
        if ($entry->hasOrigin()) {
            $meta = $entry->get('magic_translator');

            if ($meta !== null) {
                Blink::put("magic-translator:meta:{$entry->id()}", $meta);
            }
        }

        $currentSite = $entry->locale();
        $isOrigin = $entry->isRoot();

        // Always use the root entry for localization traversal and staleness.
        $rootEntry = $isOrigin ? $entry : $entry->root();
        $originSite = $rootEntry?->locale();

        // Legacy fallback source for pre-hash metadata.
        $originUpdatedAt = null;

        if ($rootEntry !== null && method_exists($rootEntry, 'lastModified')) {
            $originUpdatedAt = $rootEntry->lastModified();
        }

        $currentSourceHash = null;

        if ($rootEntry !== null) {
            $fieldDefs = FieldDefinitionBuilder::fromBlueprint($rootEntry->blueprint());
            $currentSourceHash = app(SourceHashCache::class)->get($rootEntry, $fieldDefs);
        }

        // Restrict the site list to the collection's configured sites AND the
        // sites the current user may access for editing. An empty list here
        // causes the sidebar component to hide its Translate button.
        $user = User::current();
        $allowedHandles = $user !== null
            ? AccessibleSites::forCollection($user, $entry->collection())->all()
            : [];

        $sitesData = Site::all()->filter(
            fn ($site) => in_array($site->handle(), $allowedHandles, true)
        )->map(function ($site) use ($entry, $originUpdatedAt, $currentSourceHash) {
            $siteHandle = $site->handle();
            $localization = $entry->in($siteHandle);
            $exists = $localization !== null;
            $lastTranslatedAt = null;
            $isStale = false;

            if ($localization !== null) {
                $meta = $localization->get('magic_translator');

                if (is_array($meta)) {
                    $storedHash = $meta['source_content_hash'] ?? null;

                    if (is_string($meta['last_translated_at'] ?? null) && $meta['last_translated_at'] !== '') {
                        $lastTranslatedAt = $meta['last_translated_at'];
                    }

                    if (is_string($storedHash) && $storedHash !== '' && is_string($currentSourceHash)) {
                        $isStale = $storedHash !== $currentSourceHash;
                    } elseif ($lastTranslatedAt !== null) {
                        // Legacy fallback: timestamps for pre-hash metadata.
                        if ($originUpdatedAt !== null) {
                            try {
                                $translatedAt = Carbon::parse($lastTranslatedAt);
                                $isStale = $originUpdatedAt->greaterThan($translatedAt);
                            } catch (Throwable) {
                                $lastTranslatedAt = null;
                                $isStale = false;
                            }
                        }
                    } elseif (is_string($currentSourceHash)) {
                        $isStale = true;
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
            'entry_id' => $entry->id(),
            'current_site' => $currentSite,
            'origin_site' => $originSite,
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
