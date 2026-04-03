<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Actions;

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Events\AfterEntryTranslation;
use ElSchneider\ContentTranslator\Events\BeforeEntryTranslation;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;
use ElSchneider\ContentTranslator\Reassembly\ContentReassembler;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Fields\Blueprint;

final class TranslateEntry
{
    public function __construct(
        private readonly ContentExtractor $extractor,
        private readonly ContentReassembler $reassembler,
        private readonly TranslationService $translationService,
    ) {}

    /**
     * Translate an entry to a target site/locale.
     *
     * Steps:
     *  1. Load source entry (by entryId from the default site or root)
     *  2. Resolve the source entry for the given sourceSite (or use the root/origin)
     *  3. Load or create the target localization
     *  4. Skip if overwrite=false and localization already exists
     *  5. Build field definitions from the blueprint
     *  6. Extract TranslationUnits from source entry data
     *  7. Fire BeforeEntryTranslation (listeners may modify units)
     *  8. Translate units via TranslationService
     *  9. Reassemble translated data
     * 10. Fire AfterEntryTranslation (listeners may modify translated data)
     * 11. Set translated data on the localization
     * 12. Optionally regenerate slug from translated title
     * 13. Set last_translated_at metadata
     * 14. Save the localization
     *
     * @param  array<string, mixed>  $options  Supported keys:
     *                                         - overwrite (bool, default: true) — whether to overwrite existing translations
     *                                         - generate_slug (bool, default: false) — whether to slugify the translated title
     */
    public function handle(
        string $entryId,
        string $targetSite,
        ?string $sourceSite = null,
        array $options = [],
    ): void {
        // ── 1. Load the entry (may be any localization or origin) ─────────────
        $entry = EntryFacade::find($entryId);

        if ($entry === null) {
            throw new InvalidArgumentException("Entry [{$entryId}] not found.");
        }

        // ── 2. Resolve the source entry ───────────────────────────────────────
        $sourceEntry = $this->resolveSourceEntry($entry, $sourceSite);

        // ── 3. Load or create the target localization ─────────────────────────
        // `in()` walks the localization tree; if not found, create it.
        $localization = $this->findOrMakeLocalization($entry, $targetSite);

        // ── 4. Skip if overwrite is disabled and localization already exists ──
        $alreadyExists = $entry->in($targetSite) !== null;

        if ($alreadyExists && ($options['overwrite'] ?? true) === false) {
            return;
        }

        // ── 5. Build field definitions from the blueprint ─────────────────────
        $blueprint = $sourceEntry->blueprint();
        $fieldDefs = $this->buildFieldDefinitions($blueprint);

        // ── 6. Extract TranslationUnits ───────────────────────────────────────
        $sourceData = $sourceEntry->data()->all();
        $units = $this->extractor->extract($sourceData, $fieldDefs);

        // ── 7. Fire BeforeEntryTranslation event ──────────────────────────────
        $beforeEvent = new BeforeEntryTranslation($sourceEntry, $targetSite, $units);
        event($beforeEvent);
        $units = $beforeEvent->units;

        // ── 8. Translate ──────────────────────────────────────────────────────
        if ($units !== []) {
            $sourceLocale = $sourceEntry->site()->locale();
            $targetSiteObj = \Statamic\Facades\Site::get($targetSite);

            if ($targetSiteObj === null) {
                throw new InvalidArgumentException("Target site [{$targetSite}] is not configured.");
            }

            $targetLocale = $targetSiteObj->locale();

            $units = $this->translationService->translate($units, $sourceLocale, $targetLocale);
        }

        // ── 9. Reassemble ─────────────────────────────────────────────────────
        // When all units were removed by a BeforeEntryTranslation listener
        // (or nothing was extracted), skip reassembly and use an empty map so
        // the localization is created with no translated data.
        if ($units === []) {
            $translatedData = [];
        } else {
            $translatedData = $this->reassembler->reassemble($sourceData, $units, $fieldDefs);
        }

        // ── 10. Fire AfterEntryTranslation event ──────────────────────────────
        $afterEvent = new AfterEntryTranslation($localization, $targetSite, $translatedData);
        event($afterEvent);
        $translatedData = $afterEvent->translatedData;

        // ── 11. Set translated data ───────────────────────────────────────────
        // Only set fields that are actually present in the translated data to
        // avoid creating stale keys for fields not extracted/translated.
        $dataToSet = array_filter(
            $translatedData,
            static fn (mixed $v): bool => $v !== null,
        );

        $localization->data($dataToSet);

        // ── 12. Optionally regenerate slug ────────────────────────────────────
        if (($options['generate_slug'] ?? false) === true) {
            $translatedTitle = $translatedData['title'] ?? null;

            if ($translatedTitle !== null && $translatedTitle !== '') {
                $localization->slug(Str::slug((string) $translatedTitle));
            }
        }

        // ── 13. Set last_translated_at metadata ───────────────────────────────
        $meta = $localization->get('content_translator') ?? [];

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['last_translated_at'] = now()->toIso8601String();
        $localization->set('content_translator', $meta);

        // ── 14. Save ──────────────────────────────────────────────────────────
        $localization->save();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve the entry that will be used as the translation source.
     *
     * If $sourceSite is given, look up that localization (falling back to the
     * root entry if not found). If no $sourceSite is given, use the root
     * (origin) entry so we always translate from the canonical source.
     */
    private function resolveSourceEntry(Entry $entry, ?string $sourceSite): Entry
    {
        // Walk up to the root (origin) entry.
        $root = $entry->hasOrigin() ? $entry->root() : $entry;

        if ($sourceSite === null) {
            return $root;
        }

        // If the root is the requested source site, use it directly.
        if ($root->locale() === $sourceSite) {
            return $root;
        }

        // Otherwise find the localization for the requested source site.
        $sourceLocalization = $root->in($sourceSite);

        return $sourceLocalization ?? $root;
    }

    /**
     * Find an existing localization for $targetSite or create one via
     * makeLocalization().
     *
     * We always call makeLocalization() on the root entry so that the new
     * localization has the correct origin relationship.
     */
    private function findOrMakeLocalization(Entry $entry, string $targetSite): Entry
    {
        $root = $entry->hasOrigin() ? $entry->root() : $entry;

        $existing = $root->in($targetSite);

        if ($existing !== null) {
            return $existing;
        }

        return $root->makeLocalization($targetSite);
    }

    /**
     * Build the field definitions array that ContentExtractor expects.
     *
     * Walks every top-level field in the blueprint and converts the Field
     * objects into the flat array format understood by ContentExtractor.
     * Nested fields within replicator/bard sets are normalised from Statamic's
     * ordered `[['handle' => ..., 'field' => ...]]` format into a simple
     * keyed map `['handle' => [...config...]]`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildFieldDefinitions(Blueprint $blueprint): array
    {
        return $blueprint->fields()->all()
            ->mapWithKeys(fn ($field) => [$field->handle() => $this->normalizeFieldConfig($field->config())])
            ->toArray();
    }

    /**
     * Normalize a single field's config array into the format ContentExtractor
     * expects. For top-level scalar/flat fields, the config is returned as-is.
     * For structural fields (replicator, bard, grid), nested field definitions
     * are normalized recursively.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeFieldConfig(array $config): array
    {
        $type = $config['type'] ?? 'text';

        return match ($type) {
            'replicator', 'bard' => $this->normalizeSetConfig($config),
            'grid' => $this->normalizeGridConfig($config),
            default => $config,
        };
    }

    /**
     * Normalize a replicator or bard field config.
     *
     * Handles two Statamic storage formats for sets:
     *  - Legacy flat format: `['text' => ['display' => '...', 'fields' => [...]]]`
     *  - Section-grouped format (Statamic 5+):
     *    `['main' => ['display' => '...', 'sets' => ['text' => [...]]]]`
     *
     * Nested field items (`[['handle' => 'body', 'field' => ['type' => 'text']]]`)
     * are converted to the keyed format ContentExtractor expects
     * (`['body' => ['type' => 'text']]`).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeSetConfig(array $config): array
    {
        $rawSets = $config['sets'] ?? [];

        if (empty($rawSets)) {
            return $config;
        }

        // Detect section-grouped format: first item has a nested 'sets' key.
        $firstItem = reset($rawSets);

        if (is_array($firstItem) && array_key_exists('sets', $firstItem)) {
            // Flatten all sections into a single map.
            $flattened = [];

            foreach ($rawSets as $section) {
                foreach ($section['sets'] ?? [] as $setHandle => $setConfig) {
                    $flattened[(string) $setHandle] = $setConfig;
                }
            }

            $rawSets = $flattened;
        }

        // Normalize fields within each set.
        $normalizedSets = [];

        foreach ($rawSets as $setHandle => $setConfig) {
            $normalizedSets[(string) $setHandle] = [
                'display' => $setConfig['display'] ?? $setHandle,
                'fields' => $this->normalizeFieldItems($setConfig['fields'] ?? []),
            ];
        }

        $config['sets'] = $normalizedSets;

        return $config;
    }

    /**
     * Normalize a grid field config.
     *
     * Converts the column `fields` array from Statamic's ordered item format
     * to the keyed map ContentExtractor expects.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeGridConfig(array $config): array
    {
        $rawFields = $config['fields'] ?? [];

        if (empty($rawFields)) {
            return $config;
        }

        $config['fields'] = $this->normalizeFieldItems($rawFields);

        return $config;
    }

    /**
     * Convert a Statamic-style ordered field items array into a keyed map.
     *
     * Input:  `[['handle' => 'body', 'field' => ['type' => 'text']], ...]`
     * Output: `['body' => ['type' => 'text'], ...]`
     *
     * Fieldset imports (`['import' => '...']`) and string references are
     * silently skipped since they cannot be resolved here without loading the
     * full fieldset (which would require the database / stache).
     *
     * @param  array<int|string, mixed>  $fieldItems
     * @return array<string, array<string, mixed>>
     */
    private function normalizeFieldItems(array $fieldItems): array
    {
        // Already a keyed map (ContentExtractor format) — return as-is.
        if (! isset($fieldItems[0]) && ! empty($fieldItems)) {
            // Check if this looks like an already-keyed map (string keys with array values)
            $allStringKeys = array_reduce(
                array_keys($fieldItems),
                fn (bool $carry, mixed $key): bool => $carry && is_string($key),
                true,
            );

            if ($allStringKeys) {
                // The values are already field config arrays — just normalize each one.
                return array_map(
                    fn (array $fieldConfig) => $this->normalizeFieldConfig($fieldConfig),
                    $fieldItems,
                );
            }
        }

        $result = [];

        foreach ($fieldItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            // Skip fieldset imports.
            if (isset($item['import'])) {
                continue;
            }

            if (! isset($item['handle'])) {
                continue;
            }

            $handle = (string) $item['handle'];

            // 'field' can be a string reference (fieldset path) or an inline config array.
            $fieldConfig = $item['field'] ?? [];

            if (is_string($fieldConfig)) {
                // String fieldset reference — skip (can't resolve here).
                continue;
            }

            $result[$handle] = $this->normalizeFieldConfig((array) $fieldConfig);
        }

        return $result;
    }
}
