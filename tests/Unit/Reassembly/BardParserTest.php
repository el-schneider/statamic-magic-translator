<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Extraction\BardSerializer;
use ElSchneider\MagicTranslator\Reassembly\BardParser;

beforeEach(function () {
    $this->parser = new BardParser;
    $this->serializer = new BardSerializer;
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function bardParserFixture(string $name): array
{
    $path = __DIR__.'/../../__fixtures__/bard/'.$name.'.json';

    return json_decode(file_get_contents($path), true);
}

// ── Empty content ─────────────────────────────────────────────────────────────

it('parses empty string to empty array', function () {
    $result = $this->parser->parse('', []);

    expect($result)->toBe([]);
});

// ── Plain text ────────────────────────────────────────────────────────────────

it('parses plain text with no tags', function () {
    $result = $this->parser->parse('Hello world', []);

    expect($result)->toBe([
        ['type' => 'text', 'text' => 'Hello world'],
    ]);
});

// ── Single known marks ────────────────────────────────────────────────────────

it('parses bold tag back to bold mark', function () {
    $result = $this->parser->parse('Hello <b>world</b>', []);

    expect($result)->toBe([
        ['type' => 'text', 'text' => 'Hello '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
    ]);
});

it('parses italic tag back to italic mark', function () {
    $result = $this->parser->parse('<i>emphasis</i>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'emphasis'],
    ]);
});

it('parses underline tag back to underline mark', function () {
    $result = $this->parser->parse('<u>underlined</u>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'underline']], 'text' => 'underlined'],
    ]);
});

it('parses strike tag back to strike mark', function () {
    $result = $this->parser->parse('<s>deleted</s>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'strike']], 'text' => 'deleted'],
    ]);
});

it('parses code tag back to code mark', function () {
    $result = $this->parser->parse('<code>snippet</code>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'snippet'],
    ]);
});

it('parses superscript tag back to superscript mark', function () {
    $result = $this->parser->parse('<sup>2</sup>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'superscript']], 'text' => '2'],
    ]);
});

it('parses subscript tag back to subscript mark', function () {
    $result = $this->parser->parse('<sub>2</sub>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'subscript']], 'text' => '2'],
    ]);
});

// ── Link mark ─────────────────────────────────────────────────────────────────

it('parses link tag back to link mark preserving href', function () {
    $result = $this->parser->parse('<a href="https://example.com">click here</a>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]], 'text' => 'click here'],
    ]);
});

it('parses link tag with empty href attribute', function () {
    $result = $this->parser->parse('<a href="">link</a>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '']]], 'text' => 'link'],
    ]);
});

it('parses link with multiple attributes preserving all of them', function () {
    $result = $this->parser->parse('<a href="https://example.com" target="_blank">click here</a>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'target' => '_blank']]], 'text' => 'click here'],
    ]);
});

it('preserves all link attributes including booleans and mixed quoting styles', function () {
    $html = "<a href='https://example.com' target = \"_blank\" rel='noopener noreferrer' download data-id=42 >click</a>";
    $result = $this->parser->parse($html, []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [[
            'type' => 'link',
            'attrs' => [
                'href' => 'https://example.com',
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'download' => true,
                'data-id' => '42',
            ],
        ]], 'text' => 'click'],
    ]);
});

it('unescapes html entities in link attribute values', function () {
    $result = $this->parser->parse('<a href="https://example.com/?q=&quot;x&quot;">quoted</a>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/?q="x"']]], 'text' => 'quoted'],
    ]);
});

// ── Nested marks ──────────────────────────────────────────────────────────────

it('parses nested tags back to multiple marks on single text node', function () {
    $result = $this->parser->parse('<b><i>strong emphasis</i></b>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'italic']], 'text' => 'strong emphasis'],
    ]);
});

it('parses three nested marks', function () {
    $result = $this->parser->parse('<b><i><u>all three</u></i></b>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'underline']], 'text' => 'all three'],
    ]);
});

// ── Custom / unknown marks ────────────────────────────────────────────────────

it('parses custom mark placeholder using mark map', function () {
    $markMap = [0 => ['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]];

    $result = $this->parser->parse('<span data-mark-0>styled</span>', $markMap);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]], 'text' => 'styled'],
    ]);
});

it('parses multiple custom mark placeholders using mark map', function () {
    $markMap = [
        0 => ['type' => 'customA', 'attrs' => ['x' => 1]],
        1 => ['type' => 'customB', 'attrs' => ['y' => 2]],
    ];

    $result = $this->parser->parse('<span data-mark-0>first</span> <span data-mark-1>second</span>', $markMap);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'customA', 'attrs' => ['x' => 1]]], 'text' => 'first'],
        ['type' => 'text', 'text' => ' '],
        ['type' => 'text', 'marks' => [['type' => 'customB', 'attrs' => ['y' => 2]]], 'text' => 'second'],
    ]);
});

it('parses known mark wrapping custom mark', function () {
    $markMap = [0 => ['type' => 'myMark', 'attrs' => ['foo' => 'bar']]];

    $result = $this->parser->parse('<b><span data-mark-0>styled bold</span></b>', $markMap);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'myMark', 'attrs' => ['foo' => 'bar']]], 'text' => 'styled bold'],
    ]);
});

// ── Multiple text nodes ───────────────────────────────────────────────────────

it('parses multiple text nodes with mixed marks', function () {
    $result = $this->parser->parse('Plain start, <b>bold middle</b>, plain again, <i>italic end</i>', []);

    expect($result)->toBe([
        ['type' => 'text', 'text' => 'Plain start, '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'bold middle'],
        ['type' => 'text', 'text' => ', plain again, '],
        ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'italic end'],
    ]);
});

it('merges adjacent text nodes that carry identical marks', function () {
    $result = $this->parser->parse('<b>one</b><b>two</b>', []);

    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'onetwo'],
    ]);
});

it('does not leak marks from self-closing known tags', function () {
    $result = $this->parser->parse('a<b/>b', []);

    expect($result)->toBe([
        ['type' => 'text', 'text' => 'ab'],
    ]);
});

// ── Round-trip tests with fixtures ───────────────────────────────────────────

it('round-trips plain-paragraph fixture', function () {
    $original = bardParserFixture('plain-paragraph');
    $serialized = $this->serializer->serialize($original);
    $parsed = $this->parser->parse($serialized->text, $serialized->markMap);

    expect($parsed)->toBe($original);
});

it('round-trips inline-marks fixture', function () {
    $original = bardParserFixture('inline-marks');
    $serialized = $this->serializer->serialize($original);
    $parsed = $this->parser->parse($serialized->text, $serialized->markMap);

    expect($parsed)->toBe($original);
});

it('round-trips nested-marks fixture', function () {
    $original = bardParserFixture('nested-marks');
    $serialized = $this->serializer->serialize($original);
    $parsed = $this->parser->parse($serialized->text, $serialized->markMap);

    expect($parsed)->toBe($original);
});

it('round-trips link-mark fixture', function () {
    $original = bardParserFixture('link-mark');
    $serialized = $this->serializer->serialize($original);
    $parsed = $this->parser->parse($serialized->text, $serialized->markMap);

    expect($parsed)->toBe($original);
});

it('round-trips mixed-nodes fixture', function () {
    $original = bardParserFixture('mixed-nodes');
    $serialized = $this->serializer->serialize($original);
    $parsed = $this->parser->parse($serialized->text, $serialized->markMap);

    expect($parsed)->toBe($original);
});

it('round-trips custom-marks fixture', function () {
    $original = bardParserFixture('custom-marks');
    $serialized = $this->serializer->serialize($original);
    $parsed = $this->parser->parse($serialized->text, $serialized->markMap);

    expect($parsed)->toBe($original);
});

it('round-trips all-known-marks fixture', function () {
    $original = bardParserFixture('all-known-marks');
    $serialized = $this->serializer->serialize($original);
    $parsed = $this->parser->parse($serialized->text, $serialized->markMap);

    expect($parsed)->toBe($original);
});
