<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;

beforeEach(function () {
    $this->extractor = new ContentExtractor;
});

// ── Empty / null / non-localizable ────────────────────────────────────────────

it('returns empty array for empty bard data', function () {
    $data = ['content' => []];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('returns empty array for null bard value', function () {
    $data = ['content' => null];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('skips bard field when not localizable', function () {
    $data = ['content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]];
    $fields = ['content' => ['type' => 'bard', 'localizable' => false]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('skips bard when marked translatable false', function () {
    $data = ['content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true, 'translatable' => false]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

// ── Single block node ─────────────────────────────────────────────────────────

it('extracts single paragraph as one html body unit', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('content.body');
    expect($units[0]->format)->toBe(TranslationFormat::Html);
    expect($units[0]->text)->toBe('Hello world');
    expect($units[0]->markMap)->toBe([]);
});

// ── Multiple block nodes ──────────────────────────────────────────────────────

it('joins multiple paragraphs with double newline separator', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First paragraph']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second paragraph']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe("First paragraph\n\nSecond paragraph");
});

it('handles heading nodes the same way as paragraphs', function () {
    $data = [
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Section Title']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body text']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe("Section Title\n\nBody text");
    expect($units[0]->format)->toBe(TranslationFormat::Html);
});

it('extracts prose from nested blockquote children', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Intro']]],
            ['type' => 'blockquote', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quote line 1']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quote line 2']]],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Outro']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe("Intro\n\nQuote line 1\n\nQuote line 2\n\nOutro");
});

it('extracts prose from list items in document order', function () {
    $data = [
        'content' => [
            ['type' => 'bullet_list', 'content' => [
                ['type' => 'list_item', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First item']]],
                ]],
                ['type' => 'list_item', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second item']]],
                ]],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After list']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe("First item\n\nSecond item\n\nAfter list");
});

it('skips block nodes with empty content arrays', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => []],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Real content']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe('Real content');
});

it('skips block nodes with no content key', function () {
    $data = [
        'content' => [
            ['type' => 'horizontal_rule'],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After rule']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe('After rule');
});

// ── Inline marks in body ──────────────────────────────────────────────────────

it('serializes inline bold marks in body text', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
            ]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe('Hello <b>world</b>');
    expect($units[0]->format)->toBe(TranslationFormat::Html);
    expect($units[0]->markMap)->toBe([]);
});

it('serializes inline italic and link marks in body text', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'em '],
                ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]], 'text' => 'link'],
            ]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->text)->toBe('<i>em </i><a href="https://example.com">link</a>');
    expect($units[0]->markMap)->toBe([]);
});

it('stores custom marks in the markMap with sequential indices across blocks', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]], 'text' => 'first'],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'marks' => [['type' => 'myMark', 'attrs' => ['x' => 1]]], 'text' => 'second'],
            ]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    // First block: data-mark-0, second block: data-mark-1 (renumbered from 0)
    expect($units[0]->text)->toBe("<span data-mark-0>first</span>\n\n<span data-mark-1>second</span>");
    expect($units[0]->markMap)->toBe([
        0 => ['type' => 'btsSpan', 'attrs' => ['class' => 'brand']],
        1 => ['type' => 'myMark', 'attrs' => ['x' => 1]],
    ]);
});

// ── Sets with placeholders ────────────────────────────────────────────────────

it('inserts set placeholders in body text and extracts set fields separately', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before set']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'A photo']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After set']]],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'image' => ['fields' => ['caption' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    $bodyUnit = collect($units)->first(fn ($u) => $u->format === TranslationFormat::Html);
    expect($bodyUnit)->not->toBeNull();
    expect($bodyUnit->path)->toBe('content.body');
    expect($bodyUnit->text)->toBe("Before set\n\n<x-set-0/>\n\nAfter set");

    $captionUnit = collect($units)->first(fn ($u) => $u->path === 'content.1.attrs.values.caption');
    expect($captionUnit)->not->toBeNull();
    expect($captionUnit->text)->toBe('A photo');
    expect($captionUnit->format)->toBe(TranslationFormat::Plain);
});

it('body unit is always first in the returned array', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'Photo']]],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'image' => ['fields' => ['caption' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units[0]->format)->toBe(TranslationFormat::Html);
    expect($units[0]->path)->toBe('content.body');
    expect($units[1]->path)->toBe('content.1.attrs.values.caption');
});

it('handles multiple sets with correct placeholder counters and node indices', function () {
    $data = [
        'content' => [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'First']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Middle']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'Second']]],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'image' => ['fields' => ['caption' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    $bodyUnit = collect($units)->first(fn ($u) => $u->format === TranslationFormat::Html);
    expect($bodyUnit->text)->toBe("<x-set-0/>\n\nMiddle\n\n<x-set-1/>");

    // First set is at node index 0, second at node index 2
    $first = collect($units)->first(fn ($u) => $u->path === 'content.0.attrs.values.caption');
    $second = collect($units)->first(fn ($u) => $u->path === 'content.2.attrs.values.caption');
    expect($first)->not->toBeNull();
    expect($second)->not->toBeNull();
    expect($first->text)->toBe('First');
    expect($second->text)->toBe('Second');
});

it('does not emit body unit when bard contains only sets with no prose text', function () {
    $data = [
        'content' => [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'Photo']]],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'image' => ['fields' => ['caption' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    // No body unit — only the caption unit from the set
    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('content.0.attrs.values.caption');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
});

it('inserts placeholder for unknown set type but extracts no set field units', function () {
    $data = [
        'content' => [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'unknown_type', 'data' => 'value']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'image' => ['fields' => ['caption' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    // The set's position is preserved as a placeholder in the body text
    // so the reassembler can put the original set node back correctly.
    // No set field units are extracted since the type is unknown.
    expect($units)->toHaveCount(1);
    expect($units[0]->format)->toBe(TranslationFormat::Html);
    expect($units[0]->text)->toBe("<x-set-0/>\n\nHello");
});

it('extracts multiple translatable fields from a single set', function () {
    $data = [
        'content' => [
            ['type' => 'set', 'attrs' => ['values' => [
                'type' => 'card',
                'title' => 'Card Title',
                'body' => 'Card body text',
                'image' => 'photo.jpg',
            ]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Footer']]],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'card' => [
                    'fields' => [
                        'title' => ['type' => 'text'],
                        'body' => ['type' => 'textarea'],
                        'image' => ['type' => 'assets'],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    $bodyUnit = collect($units)->first(fn ($u) => $u->format === TranslationFormat::Html);
    expect($bodyUnit->text)->toBe("<x-set-0/>\n\nFooter");

    $titleUnit = collect($units)->first(fn ($u) => $u->path === 'content.0.attrs.values.title');
    $bodyFieldUnit = collect($units)->first(fn ($u) => $u->path === 'content.0.attrs.values.body');
    expect($titleUnit->text)->toBe('Card Title');
    expect($bodyFieldUnit->text)->toBe('Card body text');
    // assets field skipped
    expect($units)->toHaveCount(3); // body, title, body field
});
