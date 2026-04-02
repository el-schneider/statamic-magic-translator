<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;

beforeEach(function () {
    $this->extractor = new ContentExtractor;
});

// ── Basic table extraction ────────────────────────────────────────────────────

it('extracts text from a single table row with a single cell', function () {
    $data = [
        'my_table' => [
            ['cells' => ['Hello']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('my_table.0.cells.0');
    expect($units[0]->text)->toBe('Hello');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
});

it('extracts text from table rows with multiple cells', function () {
    $data = [
        'my_table' => [
            ['cells' => ['Name', 'Role']],
            ['cells' => ['Alice', 'Developer']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(4);
    expect($units[0]->path)->toBe('my_table.0.cells.0');
    expect($units[0]->text)->toBe('Name');
    expect($units[1]->path)->toBe('my_table.0.cells.1');
    expect($units[1]->text)->toBe('Role');
    expect($units[2]->path)->toBe('my_table.1.cells.0');
    expect($units[2]->text)->toBe('Alice');
    expect($units[3]->path)->toBe('my_table.1.cells.1');
    expect($units[3]->text)->toBe('Developer');
});

it('all table cells are extracted as plain text format', function () {
    $data = [
        'my_table' => [
            ['cells' => ['First', 'Second']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    foreach ($units as $unit) {
        expect($unit->format)->toBe(TranslationFormat::Plain);
    }
});

it('uses correct path indices for table rows and cells', function () {
    $data = [
        'data' => [
            ['cells' => ['A1', 'B1', 'C1']],
            ['cells' => ['A2', 'B2', 'C2']],
            ['cells' => ['A3', 'B3', 'C3']],
        ],
    ];
    $fields = [
        'data' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(9);
    expect($units[0]->path)->toBe('data.0.cells.0');
    expect($units[1]->path)->toBe('data.0.cells.1');
    expect($units[2]->path)->toBe('data.0.cells.2');
    expect($units[3]->path)->toBe('data.1.cells.0');
    expect($units[4]->path)->toBe('data.1.cells.1');
    expect($units[5]->path)->toBe('data.1.cells.2');
    expect($units[6]->path)->toBe('data.2.cells.0');
    expect($units[7]->path)->toBe('data.2.cells.1');
    expect($units[8]->path)->toBe('data.2.cells.2');
});

it('combines tier 1 fields and table fields in the same extraction', function () {
    $data = [
        'title' => 'My Document',
        'pricing' => [
            ['cells' => ['Plan', 'Price']],
            ['cells' => ['Basic', '$9/mo']],
        ],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'pricing' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(5);
    expect($units[0]->path)->toBe('title');
    expect($units[1]->path)->toBe('pricing.0.cells.0');
    expect($units[2]->path)->toBe('pricing.0.cells.1');
    expect($units[3]->path)->toBe('pricing.1.cells.0');
    expect($units[4]->path)->toBe('pricing.1.cells.1');
});

// ── Handling empty/null cells ─────────────────────────────────────────────────

it('skips empty string cells', function () {
    $data = [
        'my_table' => [
            ['cells' => ['Hello', '', 'World']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('my_table.0.cells.0');
    expect($units[0]->text)->toBe('Hello');
    expect($units[1]->path)->toBe('my_table.0.cells.2');
    expect($units[1]->text)->toBe('World');
});

it('skips null cells', function () {
    $data = [
        'my_table' => [
            ['cells' => ['Hello', null, 'World']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->text)->toBe('Hello');
    expect($units[1]->text)->toBe('World');
});

it('handles rows with no cells key gracefully', function () {
    $data = [
        'my_table' => [
            ['cells' => ['Hello']],
            [],  // row with no cells key
            ['cells' => ['World']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->text)->toBe('Hello');
    expect($units[1]->text)->toBe('World');
});

// ── Edge cases ────────────────────────────────────────────────────────────────

it('skips table when field is not localizable', function () {
    $data = [
        'my_table' => [
            ['cells' => ['Name', 'Role']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => false],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('returns empty array when table data is empty', function () {
    $data = ['my_table' => []];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});

it('returns empty array when all cells are empty', function () {
    $data = [
        'my_table' => [
            ['cells' => ['', null, '']],
        ],
    ];
    $fields = [
        'my_table' => ['type' => 'table', 'localizable' => true],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toBeEmpty();
});
