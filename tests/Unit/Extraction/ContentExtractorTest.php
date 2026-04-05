<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Data\TranslationFormat;
use ElSchneider\MagicTranslator\Extraction\ContentExtractor;

beforeEach(function () {
    $this->extractor = new ContentExtractor;
});

// ── Tier 1 extraction ─────────────────────────────────────────────────────────

it('extracts a text field as plain format', function () {
    $data = ['title' => 'My Post'];
    $fields = ['title' => ['type' => 'text', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('title');
    expect($units[0]->text)->toBe('My Post');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
    expect($units[0]->translatedText)->toBeNull();
});

it('extracts a textarea field as plain format', function () {
    $data = ['meta' => 'A short description'];
    $fields = ['meta' => ['type' => 'textarea', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('meta');
    expect($units[0]->text)->toBe('A short description');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
});

it('extracts a markdown field as markdown format', function () {
    $data = ['body' => '**Hello world**'];
    $fields = ['body' => ['type' => 'markdown', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('body');
    expect($units[0]->text)->toBe('**Hello world**');
    expect($units[0]->format)->toBe(TranslationFormat::Markdown);
});

it('extracts multiple tier 1 fields preserving order', function () {
    $data = ['title' => 'My Post', 'meta' => 'Description'];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'meta' => ['type' => 'textarea', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('title');
    expect($units[0]->text)->toBe('My Post');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
    expect($units[1]->path)->toBe('meta');
    expect($units[1]->text)->toBe('Description');
    expect($units[1]->format)->toBe(TranslationFormat::Plain);
});

// ── Skipping non-localizable / opt-out fields ─────────────────────────────────

it('skips non-localizable fields', function () {
    $data = ['title' => 'My Post', 'slug' => 'my-post'];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'slug' => ['type' => 'text', 'localizable' => false],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('title');
});

it('skips fields with missing localizable key', function () {
    $data = ['title' => 'My Post', 'internal' => 'data'];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'internal' => ['type' => 'text'], // no localizable key
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('title');
});

it('skips fields with translatable false', function () {
    $data = ['title' => 'My Post', 'url' => 'https://example.com'];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'url' => ['type' => 'text', 'localizable' => true, 'translatable' => false],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('title');
});

// ── Skipping wrong types ───────────────────────────────────────────────────────

it('skips non-text field types (toggle, integer)', function () {
    $data = ['title' => 'My Post', 'published' => true, 'count' => 5];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'published' => ['type' => 'toggle', 'localizable' => true],
        'count' => ['type' => 'integer', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('title');
});

it('skips tier 2 replicator fields (to be handled in task 4)', function () {
    $data = [
        'title' => 'My Post',
        'blocks' => [['type' => 'text', 'body' => 'Hello']],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'blocks' => ['type' => 'replicator', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('title');
});

it('skips tier 2 grid fields (to be handled in task 4)', function () {
    $data = [
        'title' => 'My Post',
        'links' => [['label' => 'Home', 'url' => '/']],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'links' => ['type' => 'grid', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('title');
});

it('extracts tier 3 bard fields alongside tier 1 fields', function () {
    $data = [
        'title' => 'My Post',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'content' => ['type' => 'bard', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('title');
    expect($units[1]->path)->toBe('content.body');
    expect($units[1]->format)->toBe(TranslationFormat::Html);
    expect($units[1]->text)->toBe('Hello');
});

// ── Skipping empty / null values ──────────────────────────────────────────────

it('skips null values', function () {
    $data = ['title' => null, 'meta' => 'Description'];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'meta' => ['type' => 'textarea', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('meta');
});

it('skips empty string values', function () {
    $data = ['title' => '', 'meta' => 'Description'];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'meta' => ['type' => 'textarea', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('meta');
});

it('skips fields absent from entry data', function () {
    $data = ['meta' => 'Description']; // 'title' key does not exist
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'meta' => ['type' => 'textarea', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('meta');
});

// ── Edge cases ────────────────────────────────────────────────────────────────

it('returns empty array when no tier 1 fields exist', function () {
    $data = ['published' => true];
    $fields = ['published' => ['type' => 'toggle', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('returns empty array when data is empty', function () {
    $data = [];
    $fields = ['title' => ['type' => 'text', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('returns empty array when fields is empty', function () {
    $data = ['title' => 'My Post'];
    $fields = [];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});
