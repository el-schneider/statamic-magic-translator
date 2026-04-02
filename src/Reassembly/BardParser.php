<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Reassembly;

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
     * @param  string  $html     The serialized HTML string from BardSerializer
     * @param  array<int, array<string, mixed>>  $markMap  Mark definitions for custom marks
     * @return array<int, array<string, mixed>>  ProseMirror text node array
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
                if ($mark !== null) {
                    $markStack[] = $mark;
                }
            } elseif ($token['type'] === 'close') {
                if (! empty($markStack)) {
                    array_pop($markStack);
                }
            } else {
                // Text token — emit a ProseMirror text node.
                // Key order must match ProseMirror convention: type, marks?, text.
                if (! empty($markStack)) {
                    $node = [
                        'type' => 'text',
                        'marks' => array_values($markStack),
                        'text' => $token['text'],
                    ];
                } else {
                    $node = ['type' => 'text', 'text' => $token['text']];
                }
                $nodes[] = $node;
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
                    $tagName = trim(substr($raw, 2, -1));
                    $tokens[] = ['type' => 'close', 'tag' => strtolower($tagName)];
                } else {
                    // Opening tag: <tagname attrs>
                    $inner = substr($raw, 1, -1); // strip < and >
                    preg_match('/^([\w-]+)(.*)/su', $inner, $nameMatch);
                    $tagName = strtolower($nameMatch[1] ?? '');
                    $attrString = $nameMatch[2] ?? '';
                    $tokens[] = ['type' => 'open', 'tag' => $tagName, 'attrs' => $attrString];
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
     * Parse HTML attribute pairs from an attribute string like:
     *   ` href="https://example.com" target="_blank"`
     *
     * Quoted attribute values are decoded from HTML entities back to their
     * original characters (e.g. &quot; → ").
     *
     * @return array<string, string>
     */
    private function parseAttributes(string $attrString): array
    {
        $attrs = [];

        // Match name="value" pairs (the serializer always uses double-quoted values)
        preg_match_all('/([\w-]+)="([^"]*)"/', $attrString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[1];
            $value = html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $attrs[$name] = $value;
        }

        return $attrs;
    }
}
