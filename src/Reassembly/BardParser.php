<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Reassembly;

/**
 * Parses an HTML-tagged string (as produced by BardSerializer) back into a
 * ProseMirror content array (the inline children of a single block node).
 *
 * This is the reverse of BardSerializer. It maintains a mark stack as it
 * encounters opening and closing HTML tags, and emits text nodes decorated
 * with the current mark stack whenever text content is found.
 *
 * Known HTML tags map back to ProseMirror mark types:
 *   <b>    → bold
 *   <i>    → italic
 *   <u>    → underline
 *   <s>    → strike
 *   <code> → code
 *   <sup>  → superscript
 *   <sub>  → subscript
 *   <a href="..."> → link mark, all attributes are preserved
 *
 * Custom mark placeholders:
 *   <span data-mark-N> → look up markMap[N] and restore the full mark definition
 *
 * HTML entities in attribute values (e.g. &quot;) are decoded back to their
 * original characters.
 */
final class BardParser
{
    /** @var array<string, string> Maps HTML tag names back to ProseMirror mark types. */
    private const TAG_TO_MARK = [
        'b' => 'bold',
        'i' => 'italic',
        'u' => 'underline',
        's' => 'strike',
        'code' => 'code',
        'sup' => 'superscript',
        'sub' => 'subscript',
    ];

    /**
     * Parse an HTML-tagged string back into a ProseMirror inline content array.
     *
     * @param  string  $html  The serialized HTML string from BardSerializer
     * @param  array<int, array<string, mixed>>  $markMap  Mark definitions for custom marks
     * @return array<int, array<string, mixed>> ProseMirror text node array
     */
    public function parse(string $html, array $markMap): array
    {
        if ($html === '') {
            return [];
        }

        $tokens = $this->tokenize($html);
        $markStack = [];
        $nodes = [];

        foreach ($tokens as $token) {
            if ($token['type'] === 'open') {
                $mark = $this->resolveMark($token, $markMap);
                if ($mark !== null && ! ($token['selfClosing'] ?? false)) {
                    $markStack[] = [
                        'tag' => $token['tag'],
                        'mark' => $mark,
                    ];
                }
            } elseif ($token['type'] === 'close') {
                $tag = $token['tag'];

                for ($i = count($markStack) - 1; $i >= 0; $i--) {
                    if (($markStack[$i]['tag'] ?? null) === $tag) {
                        array_splice($markStack, $i, 1);

                        break;
                    }
                }
            } else {
                $marks = array_map(
                    static fn (array $entry): array => $entry['mark'],
                    $markStack
                );

                $this->appendTextNode($nodes, $token['text'], $marks);
            }
        }

        return $nodes;
    }

    /**
     * Tokenize the HTML string into a flat list of open tags, close tags, and text chunks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tokenize(string $html): array
    {
        $tokens = [];

        // Split the HTML into tag and non-tag segments.
        // Group 1: any <tag> or </tag>
        // Group 2: any text between tags
        preg_match_all('/(<[^>]+>)|([^<]+)/u', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (isset($match[1]) && $match[1] !== '') {
                $raw = $match[1];

                if (str_starts_with($raw, '</')) {
                    // Closing tag: </tagname>
                    $tagName = mb_trim(mb_substr($raw, 2, -1));
                    $tokens[] = ['type' => 'close', 'tag' => mb_strtolower($tagName)];
                } else {
                    $selfClosing = preg_match('/\/\s*>$/', $raw) === 1;

                    // Opening tag: <tagname attrs>
                    $inner = mb_substr($raw, 1, -1); // strip < and >
                    preg_match('/^([\w-]+)(.*)/su', $inner, $nameMatch);
                    $tagName = mb_strtolower($nameMatch[1] ?? '');
                    $attrString = $nameMatch[2] ?? '';
                    $tokens[] = [
                        'type' => 'open',
                        'tag' => $tagName,
                        'attrs' => $attrString,
                        'selfClosing' => $selfClosing,
                    ];
                }
            } elseif (isset($match[2]) && $match[2] !== '') {
                $tokens[] = ['type' => 'text', 'text' => $match[2]];
            }
        }

        return $tokens;
    }

    /**
     * Resolve an open-tag token into a ProseMirror mark definition, or null if
     * the tag cannot be mapped to a mark.
     *
     * @param  array<string, mixed>  $token
     * @param  array<int, array<string, mixed>>  $markMap
     * @return array<string, mixed>|null
     */
    private function resolveMark(array $token, array $markMap): ?array
    {
        $tagName = $token['tag'];
        $attrString = (string) ($token['attrs'] ?? '');

        // Known single-tag marks (bold, italic, etc.)
        if (isset(self::TAG_TO_MARK[$tagName])) {
            return ['type' => self::TAG_TO_MARK[$tagName]];
        }

        // Link mark — parse ALL attributes from the tag
        if ($tagName === 'a') {
            $attrs = $this->parseAttributes($attrString);

            return ['type' => 'link', 'attrs' => $attrs];
        }

        // Custom mark placeholder: <span data-mark-N>
        if ($tagName === 'span') {
            if (preg_match('/data-mark-(\d+)/', $attrString, $m)) {
                $index = (int) $m[1];

                return $markMap[$index] ?? null;
            }
        }

        return null;
    }

    /**
     * Parse HTML attributes from a tag attribute string like:
     *   ` href="https://example.com" target='_blank' download`
     *
     * Values are decoded from HTML entities (e.g. &quot; → ").
     *
     * @return array<string, mixed>
     */
    private function parseAttributes(string $attrString): array
    {
        $attrs = [];

        // Match:
        // - name="value"
        // - name='value'
        // - name=value
        // - boolean name (e.g. `download`)
        preg_match_all(
            '/([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/u',
            $attrString,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );

        foreach ($matches as $match) {
            $name = $match[1];

            if (($match[2] ?? null) !== null) {
                $rawValue = $match[2];
            } elseif (($match[3] ?? null) !== null) {
                $rawValue = $match[3];
            } elseif (($match[4] ?? null) !== null) {
                $rawValue = $match[4];
            } else {
                $attrs[$name] = true;

                continue;
            }

            $attrs[$name] = html_entity_decode($rawValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $attrs;
    }

    /**
     * Append a text node and merge with previous text node when marks match exactly.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $marks
     */
    private function appendTextNode(array &$nodes, string $text, array $marks): void
    {
        if ($text === '') {
            return;
        }

        // Key order must match ProseMirror convention: type, marks?, text.
        if ($marks !== []) {
            $node = [
                'type' => 'text',
                'marks' => array_values($marks),
                'text' => $text,
            ];
        } else {
            $node = ['type' => 'text', 'text' => $text];
        }

        $lastIndex = count($nodes) - 1;
        if ($lastIndex >= 0 && $this->canMergeTextNodes($nodes[$lastIndex], $node)) {
            $nodes[$lastIndex]['text'] .= $node['text'];

            return;
        }

        $nodes[] = $node;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function canMergeTextNodes(array $left, array $right): bool
    {
        if (($left['type'] ?? null) !== 'text' || ($right['type'] ?? null) !== 'text') {
            return false;
        }

        $leftHasMarks = array_key_exists('marks', $left);
        $rightHasMarks = array_key_exists('marks', $right);

        if ($leftHasMarks !== $rightHasMarks) {
            return false;
        }

        if ($leftHasMarks && $left['marks'] !== $right['marks']) {
            return false;
        }

        return true;
    }
}
