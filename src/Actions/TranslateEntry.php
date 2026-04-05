<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Actions;

use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Events\AfterEntryTranslation;
use ElSchneider\MagicTranslator\Events\BeforeEntryTranslation;
use ElSchneider\MagicTranslator\Extraction\ContentExtractor;
use ElSchneider\MagicTranslator\Reassembly\ContentReassembler;
use ElSchneider\MagicTranslator\Support\ContentFingerprint;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Statamic\Entries\Entry;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry as EntryFacade;

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
        $fieldDefs = FieldDefinitionBuilder::fromBlueprint($blueprint);

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
        $meta = $localization->get('magic_translator') ?? [];

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['last_translated_at'] = now()->toIso8601String();
        $meta['source_content_hash'] = ContentFingerprint::compute($sourceData, $fieldDefs);
        $localization->set('magic_translator', $meta);

        // ── 14. Save ──────────────────────────────────────────────────────────
        Blink::put("magic-translator:translating:{$localization->id()}", true);

        try {
            $localization->save();
        } finally {
            Blink::forget("magic-translator:translating:{$localization->id()}");
        }
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
}
