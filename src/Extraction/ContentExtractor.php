<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Extraction;

use ElSchneider\ContentTranslator\Data\TranslationUnit;

/**
 * Extracts translatable content from entry data into a flat list of
 * TranslationUnit value objects.
 *
 * Currently handles Tier 1 (flat text) fields only.
 * Tier 2 (replicator, grid, table) and Tier 3 (bard) will be added in
 * Tasks 4 and 7 respectively.
 */
final class ContentExtractor
{
    /**
     * Extract translatable units from entry data.
     *
     * @param  array<string, mixed>  $data    Entry data keyed by field handle.
     * @param  array<string, array<string, mixed>>  $fields  Field definitions keyed by handle.
     * @return TranslationUnit[]
     */
    public function extract(array $data, array $fields): array
    {
        $units = [];

        foreach ($fields as $handle => $fieldConfig) {
            $tier = FieldClassifier::classify($fieldConfig);

            // Tier 2 (structural) and Tier 3 (bard) — skip for now.
            // Tier 4 / Skip — always skip.
            if ($tier !== FieldTier::Tier1) {
                continue;
            }

            $value = $data[$handle] ?? null;

            // Skip absent, null, or empty string values — nothing to translate.
            if ($value === null || $value === '') {
                continue;
            }

            $format = FieldClassifier::formatForType($fieldConfig['type']);

            $units[] = new TranslationUnit(
                path: $handle,
                text: (string) $value,
                format: $format,
            );
        }

        return $units;
    }
}
