<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Extraction;

use ElSchneider\MagicTranslator\Data\TranslationFormat;

/**
 * Classifies a Statamic field config array into a FieldTier.
 *
 * Guards (applied before type lookup):
 *   - `translatable: false` → Skip  (custom addon opt-out)
 *   - `localizable` absent or false → Skip  (field does not vary per locale)
 *
 * Tiers:
 *   Tier 1 — flat text  : text, textarea, markdown, link
 *   Tier 2 — structural : replicator, grid, table
 *   Tier 3 — bard       : bard
 *   Skip   — everything else (assets, toggle, integer, float, date, color,
 *             code, select, radio, checkboxes, entries, terms, users, video,
 *             yaml, template, section, slug, unknown)
 */
final class FieldClassifier
{
    /**
     * Classify a field config array and return the appropriate FieldTier.
     *
     * @param  array<string, mixed>  $fieldConfig
     */
    public static function classify(array $fieldConfig): FieldTier
    {
        // Custom addon opt-out — explicit translatable: false skips regardless of type.
        if (($fieldConfig['translatable'] ?? true) === false) {
            return FieldTier::Skip;
        }

        // Fields must be explicitly localizable in the blueprint to be translated.
        // Missing localizable key is treated as false (non-localizable).
        if (($fieldConfig['localizable'] ?? false) !== true) {
            return FieldTier::Skip;
        }

        return match ($fieldConfig['type'] ?? '') {
            // ── Tier 1: flat text ──────────────────────────────────────────
            'text', 'textarea', 'markdown', 'link' => FieldTier::Tier1,

            // ── Tier 2: structural containers ─────────────────────────────
            'replicator', 'grid', 'table' => FieldTier::Tier2,

            // ── Tier 3: bard (ProseMirror) ────────────────────────────────
            'bard' => FieldTier::Tier3,

            // ── Skip: everything else ──────────────────────────────────────
            default => FieldTier::Skip,
        };
    }

    /**
     * Classify a field config array for use inside a structural container
     * (replicator set, grid row). The `localizable` guard is skipped because
     * the parent container already passed that check — nested field definitions
     * typically do not carry a `localizable` key. If `localizable` is
     * explicitly present and false, still skip.
     *
     * @param  array<string, mixed>  $fieldConfig
     */
    public static function classifyNested(array $fieldConfig): FieldTier
    {
        // Custom addon opt-out — explicit translatable: false still applies.
        if (($fieldConfig['translatable'] ?? true) === false) {
            return FieldTier::Skip;
        }

        // Nested fields usually omit `localizable`, but an explicit false
        // should still be respected.
        if (array_key_exists('localizable', $fieldConfig)
            && $fieldConfig['localizable'] !== true) {
            return FieldTier::Skip;
        }

        return match ($fieldConfig['type'] ?? '') {
            // ── Tier 1: flat text ──────────────────────────────────────────
            'text', 'textarea', 'markdown', 'link' => FieldTier::Tier1,

            // ── Tier 2: structural containers ─────────────────────────────
            'replicator', 'grid', 'table' => FieldTier::Tier2,

            // ── Tier 3: bard (ProseMirror) ────────────────────────────────
            'bard' => FieldTier::Tier3,

            // ── Skip: everything else ──────────────────────────────────────
            default => FieldTier::Skip,
        };
    }

    /**
     * Return the TranslationFormat appropriate for a given Tier 1 field type.
     * Defaults to Plain for any unrecognised type.
     */
    public static function formatForType(string $type): TranslationFormat
    {
        return match ($type) {
            'markdown' => TranslationFormat::Markdown,
            default => TranslationFormat::Plain,
        };
    }
}
