<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Reassembly;

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Data\TranslationUnit;

/**
 * Reassembles translated TranslationUnit objects back into the original
 * entry data structure.
 *
 * For plain/markdown units — use the dot-notation path to set the translated
 * text into a deep clone of the original data via Laravel's data_set().
 *
 * For html (Bard body) units — the path ends in ".body" (a synthetic suffix
 * added by ContentExtractor). The reassembler:
 *   1. Strips ".body" to find the real Bard field path.
 *   2. Splits the translated text by "\n\n" to recover per-block strings.
 *   3. Filters out "<x-set-N/>" placeholder lines (positional markers only).
 *   4. Walks the original Bard node list, and for each non-set block node
 *      replaces its `content` array with the next translated prose block
 *      (parsed back into ProseMirror via BardParser).
 *   5. Container nodes (blockquote, list, list_item) are traversed
 *      recursively so their inner leaf paragraphs are updated in order.
 *   6. Set nodes are left untouched — their fields are handled by the
 *      separate plain/markdown units that the extractor emits for them.
 */
final class ContentReassembler
{
    public function __construct(
        private readonly BardParser $bardParser = new BardParser,
    ) {}

    /**
     * Reassemble translated units back into the original data structure.
     *
     * @param  array<string, mixed>  $data  Original entry data
     * @param  TranslationUnit[]  $units  Translated units (translatedText may be null to skip)
     * @param  array<string, mixed>  $fields  Field definitions (kept for API symmetry with ContentExtractor)
     * @return array<string, mixed>
     */
    public function reassemble(array $data, array $units, array $fields): array
    {
        // Work on a deep clone so the caller's array is never mutated.
        $result = $this->deepClone($data);

        foreach ($units as $unit) {
            if ($unit->translatedText === null) {
                continue;
            }

            if ($unit->format === TranslationFormat::Html && str_ends_with($unit->path, '.body')) {
                // Bard body unit: path is "{bardPath}.body"
                $bardPath = mb_substr($unit->path, 0, -mb_strlen('.body'));

                if ($bardPath === '') {
                    continue;
                }

                $this->reassembleBardBody($result, $bardPath, $unit);
            } else {
                // Plain or Markdown unit: dot-path assignment.
                data_set($result, $unit->path, $unit->translatedText);
            }
        }

        return $result;
    }

    // ── Bard body reassembly ──────────────────────────────────────────────────

    /**
     * Replace the prose block content of a Bard field with translated text.
     *
     * @param  array<string, mixed>  $data  Mutable deep-clone of entry data
     * @param  string  $bardPath  Dot-path to the Bard field (without ".body")
     * @param  TranslationUnit  $unit  The Bard body translation unit
     */
    private function reassembleBardBody(array &$data, string $bardPath, TranslationUnit $unit): void
    {
        $originalNodes = data_get($data, $bardPath);

        if (! is_array($originalNodes)) {
            return;
        }

        // Split the translated text into per-block strings (same delimiter the
        // extractor used when building the body string).
        $allParts = explode("\n\n", $unit->translatedText);

        // Keep only real prose blocks — filter out set placeholders.
        $proseBlocks = array_values(array_filter(
            $allParts,
            static fn (string $part): bool => ! (bool) preg_match('/^<x-set-\d+\/>$/', $part),
        ));

        $cursor = 0;
        $reassembledNodes = $this->reassembleBardNodes(
            $originalNodes,
            $proseBlocks,
            $cursor,
            $unit->markMap,
        );

        data_set($data, $bardPath, $reassembledNodes);
    }

    /**
     * Walk a list of ProseMirror top-level nodes and replace prose-block
     * content with translated text. Set nodes are preserved unchanged.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  string[]  $proseBlocks  Translated prose (no placeholders)
     * @param  int  $cursor  Current position in $proseBlocks (by ref)
     * @param  array<int, array<string, mixed>>  $markMap
     * @return list<array<string, mixed>>
     */
    private function reassembleBardNodes(array $nodes, array $proseBlocks, int &$cursor, array $markMap): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'set') {
                // Set nodes are handled via separate TranslationUnit paths.
                $result[] = $node;

                continue;
            }

            $result[] = $this->reassembleBardNode($node, $proseBlocks, $cursor, $markMap);
        }

        return $result;
    }

    /**
     * Replace the content of a single ProseMirror block node with the next
     * translated prose block. Recursively handles container nodes
     * (blockquote, bullet_list, ordered_list, list_item) by descending into
     * their children.
     *
     * @param  array<string, mixed>  $node
     * @param  string[]  $proseBlocks
     * @param  array<int, array<string, mixed>>  $markMap
     * @return array<string, mixed>
     */
    private function reassembleBardNode(array $node, array $proseBlocks, int &$cursor, array $markMap): array
    {
        $content = $node['content'] ?? null;

        // Nodes without content (e.g. horizontal_rule) or with an empty
        // content array are preserved unchanged — the extractor skipped them
        // and produced no body part for them.
        if (! is_array($content) || $content === []) {
            return $node;
        }

        // Determine whether this is a leaf block (paragraph, heading, …) whose
        // content array holds inline text nodes directly, or a container node
        // (blockquote, list, list_item) that holds nested block nodes.
        $hasDirectText = count(array_filter(
            $content,
            static fn (mixed $child): bool => is_array($child) && (($child['type'] ?? '') === 'text'),
        )) > 0;

        if ($hasDirectText) {
            // Leaf block: consume the next translated prose block.
            if ($cursor < count($proseBlocks)) {
                $node['content'] = $this->bardParser->parse($proseBlocks[$cursor], $markMap);
                $cursor++;
            }

            return $node;
        }

        // Container node: recurse into its children so every nested leaf
        // block is updated in document order.
        $node['content'] = $this->reassembleBardNodes($content, $proseBlocks, $cursor, $markMap);

        return $node;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Deep-clone an array via JSON round-trip.
     *
     * This is intentionally simple — entry data contains only JSON-serialisable
     * scalar types and arrays.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deepClone(array $data): array
    {
        return json_decode((string) json_encode($data), true);
    }
}
