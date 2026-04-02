<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;

beforeEach(function () {
    $this->extractor = new ContentExtractor;
});

// ── Basic replicator extraction ───────────────────────────────────────────────

it('extracts text from a single replicator set', function () {
    $data = [
        'blocks' => [
            ['type' => 'text', 'body' => 'Hello world'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('blocks.0.body');
    expect($units[0]->text)->toBe('Hello world');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
});

it('extracts text from multiple replicator sets of different types', function () {
    $data = [
        'blocks' => [
            ['type' => 'text', 'body' => 'Hello world'],
            ['type' => 'image', 'src' => 'photo.jpg', 'caption' => 'Nice photo'],
            ['type' => 'quote', 'text' => 'A quote', 'cite' => 'Author'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
                'image' => ['fields' => ['src' => ['type' => 'assets'], 'caption' => ['type' => 'text']]],
                'quote' => ['fields' => ['text' => ['type' => 'text'], 'cite' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(4); // body, caption, text, cite (src is assets → skipped)
    expect($units[0]->path)->toBe('blocks.0.body');
    expect($units[0]->text)->toBe('Hello world');
    expect($units[1]->path)->toBe('blocks.1.caption');
    expect($units[1]->text)->toBe('Nice photo');
    expect($units[2]->path)->toBe('blocks.2.text');
    expect($units[2]->text)->toBe('A quote');
    expect($units[3]->path)->toBe('blocks.2.cite');
    expect($units[3]->text)->toBe('Author');
});

it('uses correct path indices for replicator items', function () {
    $data = [
        'blocks' => [
            ['type' => 'text', 'body' => 'First'],
            ['type' => 'text', 'body' => 'Second'],
            ['type' => 'text', 'body' => 'Third'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(3);
    expect($units[0]->path)->toBe('blocks.0.body');
    expect($units[1]->path)->toBe('blocks.1.body');
    expect($units[2]->path)->toBe('blocks.2.body');
});

it('combines tier 1 fields and replicator fields in the same extraction', function () {
    $data = [
        'title' => 'My Post',
        'blocks' => [
            ['type' => 'text', 'body' => 'Content body'],
        ],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('title');
    expect($units[1]->path)->toBe('blocks.0.body');
});

// ── Field type handling inside sets ──────────────────────────────────────────

it('extracts markdown fields inside replicator sets with markdown format', function () {
    $data = [
        'blocks' => [
            ['type' => 'article', 'body' => '**Bold text**'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'article' => ['fields' => ['body' => ['type' => 'markdown']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('blocks.0.body');
    expect($units[0]->format)->toBe(TranslationFormat::Markdown);
});

it('skips non-text fields inside replicator sets', function () {
    $data = [
        'blocks' => [
            ['type' => 'image', 'src' => 'photo.jpg', 'alt' => 'A photo', 'published' => true, 'width' => 800],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'image' => [
                    'fields' => [
                        'src' => ['type' => 'assets'],
                        'alt' => ['type' => 'text'],
                        'published' => ['type' => 'toggle'],
                        'width' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('blocks.0.alt');
});

it('skips empty or null values inside replicator sets', function () {
    $data = [
        'blocks' => [
            ['type' => 'text', 'body' => 'Hello', 'subtitle' => null, 'note' => ''],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => [
                    'body' => ['type' => 'text'],
                    'subtitle' => ['type' => 'text'],
                    'note' => ['type' => 'text'],
                ]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('blocks.0.body');
});

it('skips fields with translatable false inside replicator sets', function () {
    $data = [
        'blocks' => [
            ['type' => 'link', 'url' => 'https://example.com', 'label' => 'Visit us'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'link' => ['fields' => [
                    'url' => ['type' => 'text', 'translatable' => false],
                    'label' => ['type' => 'text'],
                ]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('blocks.0.label');
});

// ── Unknown/missing set type handling ─────────────────────────────────────────

it('skips replicator items whose type is not defined in sets', function () {
    $data = [
        'blocks' => [
            ['type' => 'undefined_type', 'body' => 'Should be skipped'],
            ['type' => 'text', 'body' => 'Should be extracted'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('blocks.1.body');
});

it('skips replicator when field is not localizable', function () {
    $data = [
        'blocks' => [
            ['type' => 'text', 'body' => 'Hello world'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => false,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('returns empty array when replicator data is empty', function () {
    $data = ['blocks' => []];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

// ── Nested replicator inside replicator ───────────────────────────────────────

it('extracts text from nested replicator inside replicator', function () {
    $data = [
        'outer' => [
            [
                'type' => 'section',
                'title' => 'Section title',
                'inner' => [
                    ['type' => 'paragraph', 'body' => 'Nested paragraph'],
                ],
            ],
        ],
    ];
    $fields = [
        'outer' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'section' => [
                    'fields' => [
                        'title' => ['type' => 'text'],
                        'inner' => [
                            'type' => 'replicator',
                            'sets' => [
                                'paragraph' => ['fields' => ['body' => ['type' => 'text']]],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('outer.0.title');
    expect($units[0]->text)->toBe('Section title');
    expect($units[1]->path)->toBe('outer.0.inner.0.body');
    expect($units[1]->text)->toBe('Nested paragraph');
});
