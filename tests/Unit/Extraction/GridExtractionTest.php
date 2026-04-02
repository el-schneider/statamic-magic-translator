<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;

beforeEach(function () {
    $this->extractor = new ContentExtractor;
});

// ── Basic grid extraction ─────────────────────────────────────────────────────

it('extracts text from a single grid row', function () {
    $data = [
        'links' => [
            ['url' => 'https://example.com', 'label' => 'Example'],
        ],
    ];
    $fields = [
        'links' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'url' => ['type' => 'text', 'translatable' => false],
                'label' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('links.0.label');
    expect($units[0]->text)->toBe('Example');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
});

it('extracts text from multiple grid rows', function () {
    $data = [
        'links' => [
            ['url' => 'https://example.com', 'label' => 'Example'],
            ['url' => 'https://other.com', 'label' => 'Other'],
        ],
    ];
    $fields = [
        'links' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'url' => ['type' => 'text', 'translatable' => false],
                'label' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('links.0.label');
    expect($units[0]->text)->toBe('Example');
    expect($units[1]->path)->toBe('links.1.label');
    expect($units[1]->text)->toBe('Other');
});

it('uses correct path indices for grid rows', function () {
    $data = [
        'items' => [
            ['name' => 'First'],
            ['name' => 'Second'],
            ['name' => 'Third'],
        ],
    ];
    $fields = [
        'items' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'name' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(3);
    expect($units[0]->path)->toBe('items.0.name');
    expect($units[1]->path)->toBe('items.1.name');
    expect($units[2]->path)->toBe('items.2.name');
});

it('extracts multiple text columns from each grid row', function () {
    $data = [
        'team' => [
            ['name' => 'Alice', 'role' => 'Developer', 'bio' => 'Loves PHP'],
        ],
    ];
    $fields = [
        'team' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'name' => ['type' => 'text'],
                'role' => ['type' => 'text'],
                'bio' => ['type' => 'textarea'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(3);
    expect($units[0]->path)->toBe('team.0.name');
    expect($units[0]->text)->toBe('Alice');
    expect($units[1]->path)->toBe('team.0.role');
    expect($units[1]->text)->toBe('Developer');
    expect($units[2]->path)->toBe('team.0.bio');
    expect($units[2]->text)->toBe('Loves PHP');
});

it('combines tier 1 fields and grid fields in the same extraction', function () {
    $data = [
        'title' => 'My Page',
        'links' => [
            ['label' => 'Click me'],
        ],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'links' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'label' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('title');
    expect($units[1]->path)->toBe('links.0.label');
});

// ── Field type handling inside grid columns ───────────────────────────────────

it('extracts markdown columns inside grid rows with markdown format', function () {
    $data = [
        'faqs' => [
            ['question' => 'What is it?', 'answer' => '**It is cool.**'],
        ],
    ];
    $fields = [
        'faqs' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'question' => ['type' => 'text'],
                'answer' => ['type' => 'markdown'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('faqs.0.question');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
    expect($units[1]->path)->toBe('faqs.0.answer');
    expect($units[1]->format)->toBe(TranslationFormat::Markdown);
});

it('skips non-text column types inside grid', function () {
    $data = [
        'features' => [
            ['label' => 'Feature 1', 'enabled' => true, 'count' => 5, 'icon' => 'star'],
        ],
    ];
    $fields = [
        'features' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'label' => ['type' => 'text'],
                'enabled' => ['type' => 'toggle'],
                'count' => ['type' => 'integer'],
                'icon' => ['type' => 'assets'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('features.0.label');
});

it('skips empty and null values inside grid rows', function () {
    $data = [
        'items' => [
            ['name' => 'Alice', 'note' => null, 'extra' => ''],
        ],
    ];
    $fields = [
        'items' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'name' => ['type' => 'text'],
                'note' => ['type' => 'text'],
                'extra' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('items.0.name');
});

it('skips fields with translatable false inside grid', function () {
    $data = [
        'links' => [
            ['url' => 'https://example.com', 'label' => 'Example'],
        ],
    ];
    $fields = [
        'links' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'url' => ['type' => 'text', 'translatable' => false],
                'label' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('links.0.label');
});

it('skips fields with localizable false inside grid', function () {
    $data = [
        'links' => [
            ['label' => 'Example', 'internal_note' => 'Do not translate'],
        ],
    ];
    $fields = [
        'links' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'label' => ['type' => 'text'],
                'internal_note' => ['type' => 'text', 'localizable' => false],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('links.0.label');
});

// ── Edge cases ────────────────────────────────────────────────────────────────

it('skips grid when field is not localizable', function () {
    $data = [
        'items' => [
            ['name' => 'Alice'],
        ],
    ];
    $fields = [
        'items' => [
            'type' => 'grid',
            'localizable' => false,
            'fields' => [
                'name' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('returns empty array when grid data is empty', function () {
    $data = ['items' => []];
    $fields = [
        'items' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                'name' => ['type' => 'text'],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});
