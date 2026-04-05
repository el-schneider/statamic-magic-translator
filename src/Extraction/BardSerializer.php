<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Extraction;

/**
 * Serializes a ProseMirror `content` array (the inline children of a single
 * block node) into an HTML-tagged string suitable for translation services,
 * plus a markMap that stores the definitions of any unknown/custom marks so
 * they can be restored after translation.
 *
 * Known marks map to standard HTML tags:
 *   bold        → <b>
 *   italic      → <i>
 *   underline   → <u>
 *   strike      → <s>
 *   code        → <code>
 *   superscript → <sup>
 *   subscript   → <sub>
 *   link        → <a href="...">
 *
 * Unknown/custom marks (e.g. btsSpan) → <span data-mark-N> where N is the
 * sequential index in the markMap.  The full mark definition is stored at
 * markMap[N] so it can be re-applied by the BardParser after translation.
 *
 * When a text node carries multiple marks, each mark wraps the next:
 *   marks = [bold, italic]  →  <b><i>text</i></b>
 */
final class BardSerializer
{
    /** @var array<string, string> Maps known ProseMirror mark types to their HTML tags. */
    private const KNOWN_MARKS = [
        'bold' => 'b',
        'italic' => 'i',
        'underline' => 'u',
        'strike' => 's',
        'code' => 'code',
        'superscript' => 'sup',
        'subscript' => 'sub',
    ];

    /**
     * Serialize the content array of a single block node.
     *
     * @param  array<int, array<string, mixed>>  $content  ProseMirror inline node array
     */
    public function serialize(array $content): BardSerializerResult
    {
        $text = '';
        $markMap = [];
        $customIndex = 0;

        foreach ($content as $node) {
            // Only process text nodes; skip hard_break, image, etc.
            if (($node['type'] ?? '') !== 'text') {
                continue;
            }

            $nodeText = $node['text'] ?? '';
            $marks = $node['marks'] ?? [];

            if (empty($marks)) {
                $text .= $nodeText;

                continue;
            }

            // Build open/close tag wrappers for each mark.
            // Marks are applied left-to-right; the first mark is the outermost tag.
            $openTags = '';
            $closeTags = '';

            foreach ($marks as $mark) {
                $markType = $mark['type'] ?? '';

                if (isset(self::KNOWN_MARKS[$markType])) {
                    $tag = self::KNOWN_MARKS[$markType];
                    $openTags .= "<{$tag}>";
                    $closeTags = "</{$tag}>".$closeTags;
                } elseif ($markType === 'link') {
                    $attrs = $mark['attrs'] ?? [];
                    if (! is_array($attrs)) {
                        $attrs = [];
                    }

                    if (! array_key_exists('href', $attrs)) {
                        $attrs = ['href' => ''] + $attrs;
                    }

                    $openTags .= '<a'.$this->serializeAttributes($attrs).'>';
                    $closeTags = '</a>'.$closeTags;
                } else {
                    // Unknown/custom mark — store in markMap and emit a placeholder.
                    $markMap[$customIndex] = $mark;
                    $openTags .= "<span data-mark-{$customIndex}>";
                    $closeTags = '</span>'.$closeTags;
                    $customIndex++;
                }
            }

            $text .= $openTags.$nodeText.$closeTags;
        }

        return new BardSerializerResult($text, $markMap);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function serializeAttributes(array $attrs): string
    {
        $serialized = '';

        foreach ($attrs as $name => $value) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $serialized .= ' '.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $escapedName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $serialized .= " {$escapedName}=\"{$escapedValue}\"";
        }

        return $serialized;
    }
}
