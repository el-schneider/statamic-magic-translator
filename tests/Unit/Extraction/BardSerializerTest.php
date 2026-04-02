<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Extraction\BardSerializer;

beforeEach(function () {
    $this->serializer = new BardSerializer;
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function bardFixture(string $name): array
{
    $path = __DIR__.'/../../__fixtures__/bard/'.$name.'.json';

    return json_decode(file_get_contents($path), true);
}

// ── Empty content ─────────────────────────────────────────────────────────────

it('serializes empty content array to empty string', function () {
    $result = $this->serializer->serialize([]);

    expect($result->text)->toBe('');
    expect($result->markMap)->toBe([]);
});

// ── Plain text ────────────────────────────────────────────────────────────────

it('serializes plain text with no marks', function () {
    $content = [['type' => 'text', 'text' => 'Hello world']];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('Hello world');
    expect($result->markMap)->toBe([]);
});

it('serializes text node with empty string', function () {
    $content = [['type' => 'text', 'text' => '']];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('');
    expect($result->markMap)->toBe([]);
});

it('serializes plain paragraph fixture', function () {
    $result = $this->serializer->serialize(bardFixture('plain-paragraph'));

    expect($result->text)->toBe('Hello world');
    expect($result->markMap)->toBe([]);
});

// ── Single known marks ────────────────────────────────────────────────────────

it('serializes bold mark as <b> tag', function () {
    $content = [
        ['type' => 'text', 'text' => 'Hello '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('Hello <b>world</b>');
    expect($result->markMap)->toBe([]);
});

it('serializes italic mark as <i> tag', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'emphasis'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<i>emphasis</i>');
    expect($result->markMap)->toBe([]);
});

it('serializes underline mark as <u> tag', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'underline']], 'text' => 'underlined'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<u>underlined</u>');
});

it('serializes strike mark as <s> tag', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'strike']], 'text' => 'deleted'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<s>deleted</s>');
});

it('serializes code mark as <code> tag', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'snippet'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<code>snippet</code>');
});

it('serializes superscript mark as <sup> tag', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'superscript']], 'text' => '2'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<sup>2</sup>');
});

it('serializes subscript mark as <sub> tag', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'subscript']], 'text' => '2'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<sub>2</sub>');
});

// ── Link mark ─────────────────────────────────────────────────────────────────

it('serializes link mark as <a> tag preserving href', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]], 'text' => 'click here'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<a href="https://example.com">click here</a>');
    expect($result->markMap)->toBe([]);
});

it('serializes link mark even without href attribute', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => []]], 'text' => 'link'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<a href="">link</a>');
});

it('serializes link fixture with href and additional attrs', function () {
    $fixture = bardFixture('link-mark');

    $result = $this->serializer->serialize($fixture);

    expect($result->text)->toBe('Visit <a href="https://example.com" target="_blank">click here</a> for more');
    expect($result->markMap)->toBe([]);
});

it('escapes link attribute values when serializing', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/?q="x"']]], 'text' => 'quoted'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<a href="https://example.com/?q=&quot;x&quot;">quoted</a>');
});

// ── Nested marks ──────────────────────────────────────────────────────────────

it('serializes nested marks (bold + italic) with outermost first', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'italic']], 'text' => 'strong emphasis'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<b><i>strong emphasis</i></b>');
    expect($result->markMap)->toBe([]);
});

it('serializes nested marks fixture', function () {
    $result = $this->serializer->serialize(bardFixture('nested-marks'));

    expect($result->text)->toBe('This is <b><i>strong emphasis</i></b> here');
});

it('serializes three nested marks', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'underline']], 'text' => 'all three'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<b><i><u>all three</u></i></b>');
});

// ── Custom / unknown marks ────────────────────────────────────────────────────

it('serializes a custom mark as <span data-mark-N> with mark stored in markMap', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]], 'text' => 'styled'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<span data-mark-0>styled</span>');
    expect($result->markMap)->toBe([0 => ['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]]);
});

it('serializes custom marks fixture preserving full mark definition', function () {
    $result = $this->serializer->serialize(bardFixture('custom-marks'));

    expect($result->text)->toBe('This is <span data-mark-0>styled text</span> here');
    expect($result->markMap[0])->toBe(['type' => 'btsSpan', 'attrs' => ['class' => 'brand', 'id' => 'logo']]);
});

it('assigns sequential indexes to multiple custom marks', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'customA', 'attrs' => ['x' => 1]]], 'text' => 'first'],
        ['type' => 'text', 'text' => ' '],
        ['type' => 'text', 'marks' => [['type' => 'customB', 'attrs' => ['y' => 2]]], 'text' => 'second'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<span data-mark-0>first</span> <span data-mark-1>second</span>');
    expect($result->markMap[0])->toBe(['type' => 'customA', 'attrs' => ['x' => 1]]);
    expect($result->markMap[1])->toBe(['type' => 'customB', 'attrs' => ['y' => 2]]);
});

it('nests a custom mark inside a known mark', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'myMark', 'attrs' => ['foo' => 'bar']]], 'text' => 'styled bold'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('<b><span data-mark-0>styled bold</span></b>');
    expect($result->markMap[0])->toBe(['type' => 'myMark', 'attrs' => ['foo' => 'bar']]);
});

// ── Multiple text nodes ───────────────────────────────────────────────────────

it('serializes multiple text nodes concatenating in order', function () {
    $content = [
        ['type' => 'text', 'text' => 'Plain start, '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'bold middle'],
        ['type' => 'text', 'text' => ', plain again, '],
        ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'italic end'],
    ];

    $result = $this->serializer->serialize($content);

    expect($result->text)->toBe('Plain start, <b>bold middle</b>, plain again, <i>italic end</i>');
    expect($result->markMap)->toBe([]);
});

it('serializes mixed-nodes fixture', function () {
    $result = $this->serializer->serialize(bardFixture('mixed-nodes'));

    expect($result->text)->toBe('Plain start, <b>bold middle</b>, plain again, <i>italic end</i>');
    expect($result->markMap)->toBe([]);
});

it('serializes inline-marks fixture with all distinct marks', function () {
    $result = $this->serializer->serialize(bardFixture('inline-marks'));

    expect($result->text)->toBe('Hello <b>bold</b> and <i>italic</i> and <u>underline</u> text');
    expect($result->markMap)->toBe([]);
});

it('serializes all-known-marks fixture', function () {
    $result = $this->serializer->serialize(bardFixture('all-known-marks'));

    expect($result->text)->toBe('<b>bold</b> <i>italic</i> <u>underline</u> <s>strike</s> <code>code</code> <sup>sup</sup> <sub>sub</sub>');
    expect($result->markMap)->toBe([]);
});

// ── Result object ─────────────────────────────────────────────────────────────

it('returns an object with text and markMap properties', function () {
    $result = $this->serializer->serialize([['type' => 'text', 'text' => 'hi']]);

    expect($result)->toHaveProperty('text');
    expect($result)->toHaveProperty('markMap');
    expect($result->text)->toBeString();
    expect($result->markMap)->toBeArray();
});
