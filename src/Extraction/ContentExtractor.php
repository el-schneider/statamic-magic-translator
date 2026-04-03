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
 * Tier 3 — bard (ProseMirror) — body text + set field recursion
 */
final class ContentExtractor
{
    public function __construct(
        private readonly BardSerializer $bardSerializer = new BardSerializer,
    ) {}

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
            } elseif ($tier === FieldTier::Tier3) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                // Bard fields may store raw markdown (string) instead of
                // ProseMirror JSON — e.g. default entries from starter kits
                // that haven't been edited in the Bard editor yet.
                if (is_string($value)) {
                    $units[] = new TranslationUnit(
                        path: $fullPath,
                        text: $value,
                        format: TranslationFormat::Markdown,
                    );

                    continue;
                }

                if (! is_array($value)) {
                    continue;
                }

                $units = array_merge(
                    $units,
                    $this->extractBard($fieldConfig, $value, $fullPath),
                );
            }
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

    /**
     * Extract from a bard (ProseMirror) field.
     *
     * Produces:
     *  - One `TranslationUnit` (format: Html, path: "{path}.body") for all prose
     *    block nodes joined by "\n\n", with "<x-set-N/>" placeholders in place
     *    of set nodes.  Only emitted when at least one block node carries real
     *    text content (i.e., not every part is a placeholder).
     *  - Separate `TranslationUnit`s for each set's translatable fields, using
     *    the existing Tier 1/2/3 logic recursively.
     *
     * The body unit is always prepended first in the returned array.
     *
     * @param  array<string, mixed>  $fieldConfig  Bard field definition
     * @param  list<array<string, mixed>>  $nodes  ProseMirror document nodes
     * @param  string  $path  Dot-path prefix for this bard field
     * @return TranslationUnit[]
     */
    private function extractBard(array $fieldConfig, array $nodes, string $path): array
    {
        $setUnits = [];
        $bodyParts = [];
        $setCounter = 0;
        $combinedMarkMap = [];
        $markMapOffset = 0;
        $sets = $fieldConfig['sets'] ?? [];

        foreach ($nodes as $nodeIndex => $node) {
            $nodeType = $node['type'] ?? '';

            if ($nodeType === 'set') {
                // Insert a placeholder so the body string preserves set positions.
                $bodyParts[] = "<x-set-{$setCounter}/>";
                $setCounter++;

                // Recurse into the set's translatable fields.
                $setValues = $node['attrs']['values'] ?? [];
                $setType = $setValues['type'] ?? null;

                if ($setType !== null && isset($sets[$setType]['fields'])) {
                    $setPath = "{$path}.{$nodeIndex}.attrs.values";
                    $setUnits = array_merge(
                        $setUnits,
                        $this->extractWithPrefix(
                            $setValues,
                            $sets[$setType]['fields'],
                            $setPath,
                            insideContainer: true,
                        ),
                    );
                }
            } else {
                foreach ($this->collectBardBlockSerializations($node) as $serialized) {
                    if ($serialized->text === '') {
                        continue;
                    }

                    if ($serialized->markMap !== []) {
                        // Renumber custom-mark indices so they are globally sequential
                        // across the combined body text (avoids collisions when merging).
                        $renumberedText = (string) preg_replace_callback(
                            '/data-mark-(\d+)/',
                            fn (array $m): string => 'data-mark-'.((int) $m[1] + $markMapOffset),
                            $serialized->text,
                        );

                        foreach ($serialized->markMap as $idx => $mark) {
                            $combinedMarkMap[$idx + $markMapOffset] = $mark;
                        }

                        $markMapOffset += count($serialized->markMap);
                        $bodyParts[] = $renumberedText;
                    } else {
                        $bodyParts[] = $serialized->text;
                    }
                }
            }
        }

        // Only emit a body unit when there is at least one block with real
        // translatable prose (not just set placeholders).
        $hasRealText = count(array_filter(
            $bodyParts,
            static fn (string $part): bool => ! (bool) preg_match('/^<x-set-\d+\/>$/', $part),
        )) > 0;

        if ($hasRealText) {
            array_unshift($setUnits, new TranslationUnit(
                path: "{$path}.body",
                text: implode("\n\n", $bodyParts),
                format: TranslationFormat::Html,
                markMap: $combinedMarkMap,
            ));
        }

        return $setUnits;
    }

    /**
     * Collect serialized prose blocks from a bard node.
     *
     * Some nodes (e.g. paragraph, heading) contain inline text nodes directly.
     * Others (e.g. blockquote, bullet_list, ordered_list, list_item) contain
     * nested block nodes that eventually hold inline text. This helper recurses
     * until it reaches a node whose `content` contains text nodes.
     *
     * @param  array<string, mixed>  $node
     * @return BardSerializerResult[]
     */
    private function collectBardBlockSerializations(array $node): array
    {
        $content = $node['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return [];
        }

        $hasDirectTextNodes = count(array_filter(
            $content,
            static fn (mixed $child): bool => is_array($child) && (($child['type'] ?? '') === 'text'),
        )) > 0;

        if ($hasDirectTextNodes) {
            return [$this->bardSerializer->serialize($content)];
        }

        $results = [];

        foreach ($content as $child) {
            if (! is_array($child)) {
                continue;
            }

            $results = array_merge($results, $this->collectBardBlockSerializations($child));
        }

        return $results;
    }
}
