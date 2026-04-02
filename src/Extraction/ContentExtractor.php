<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Extraction;

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Data\TranslationUnit;

/**
 * Extracts translatable content from entry data into a flat list of
 * TranslationUnit value objects.
 *
 * Tier 1 — flat text fields (text, textarea, markdown, link)
 * Tier 2 — structural containers (replicator, grid, table)
 * Tier 3 — bard (ProseMirror) — will be added in Task 7
 */
final class ContentExtractor
{
    /**
     * Extract translatable units from entry data.
     *
     * @param  array<string, mixed>  $data  Entry data keyed by field handle.
     * @param  array<string, array<string, mixed>>  $fields  Field definitions keyed by handle.
     * @return TranslationUnit[]
     */
    public function extract(array $data, array $fields): array
    {
        return $this->extractWithPrefix($data, $fields, '');
    }

    /**
     * Internal recursive extraction with a dot-path prefix.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $fields
     * @param  string  $pathPrefix  Already-built path prefix (e.g. "blocks.0")
     * @param  bool  $insideContainer  When true, skip the `localizable` guard
     *                                 (parent already passed it).
     * @return TranslationUnit[]
     */
    private function extractWithPrefix(
        array $data,
        array $fields,
        string $pathPrefix,
        bool $insideContainer = false,
    ): array {
        $units = [];

        foreach ($fields as $handle => $fieldConfig) {
            $tier = $insideContainer
                ? FieldClassifier::classifyNested($fieldConfig)
                : FieldClassifier::classify($fieldConfig);

            $fullPath = $pathPrefix !== '' ? "{$pathPrefix}.{$handle}" : $handle;
            $value = $data[$handle] ?? null;

            if ($tier === FieldTier::Tier1) {
                // Skip absent, null, or empty string values.
                if ($value === null || $value === '') {
                    continue;
                }

                $format = FieldClassifier::formatForType($fieldConfig['type']);

                $units[] = new TranslationUnit(
                    path: $fullPath,
                    text: (string) $value,
                    format: $format,
                );
            } elseif ($tier === FieldTier::Tier2) {
                // Skip absent, null, or non-array values.
                if (! is_array($value) || $value === []) {
                    continue;
                }

                $units = array_merge(
                    $units,
                    $this->extractTier2($fieldConfig, $value, $fullPath),
                );
            }
            // Tier 3 (bard) and Skip — not handled here; bard comes in Task 7.
        }

        return $units;
    }

    /**
     * Dispatch extraction to the appropriate Tier 2 handler.
     *
     * @param  array<string, mixed>  $fieldConfig
     * @param  array<int, mixed>  $value
     * @return TranslationUnit[]
     */
    private function extractTier2(array $fieldConfig, array $value, string $path): array
    {
        return match ($fieldConfig['type']) {
            'replicator' => $this->extractReplicator($fieldConfig, $value, $path),
            'grid' => $this->extractGrid($fieldConfig, $value, $path),
            'table' => $this->extractTable($value, $path),
            default => [],
        };
    }

    /**
     * Extract from a replicator field.
     *
     * Each item in $blocks is an associative array with a `type` key identifying
     * which set definition applies. The set definition lives in
     * $fieldConfig['sets'][$type]['fields'].
     *
     * @param  array<string, mixed>  $fieldConfig
     * @param  list<array<string, mixed>>  $blocks
     * @return TranslationUnit[]
     */
    private function extractReplicator(array $fieldConfig, array $blocks, string $path): array
    {
        $units = [];
        $sets = $fieldConfig['sets'] ?? [];

        foreach ($blocks as $index => $block) {
            $setType = $block['type'] ?? null;
            if ($setType === null) {
                continue;
            }

            $setFields = $sets[$setType]['fields'] ?? [];
            if ($setFields === []) {
                continue;
            }

            $units = array_merge(
                $units,
                $this->extractWithPrefix($block, $setFields, "{$path}.{$index}", insideContainer: true),
            );
        }

        return $units;
    }

    /**
     * Extract from a grid field.
     *
     * Each item in $rows is an associative array of column values.
     * Column definitions live in $fieldConfig['fields'].
     *
     * @param  array<string, mixed>  $fieldConfig
     * @param  list<array<string, mixed>>  $rows
     * @return TranslationUnit[]
     */
    private function extractGrid(array $fieldConfig, array $rows, string $path): array
    {
        $units = [];
        $columnFields = $fieldConfig['fields'] ?? [];

        if ($columnFields === []) {
            return [];
        }

        foreach ($rows as $index => $row) {
            $units = array_merge(
                $units,
                $this->extractWithPrefix($row, $columnFields, "{$path}.{$index}", insideContainer: true),
            );
        }

        return $units;
    }

    /**
     * Extract from a table field.
     *
     * Each row has a `cells` key with a list of plain string values.
     * There are no field definitions — every cell is a translatable plain-text string.
     *
     * @param  list<array{cells?: list<string|null>}>  $rows
     * @return TranslationUnit[]
     */
    private function extractTable(array $rows, string $path): array
    {
        $units = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = $row['cells'] ?? [];

            foreach ($cells as $cellIndex => $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }

                $units[] = new TranslationUnit(
                    path: "{$path}.{$rowIndex}.cells.{$cellIndex}",
                    text: (string) $cell,
                    format: TranslationFormat::Plain,
                );
            }
        }

        return $units;
    }
}
