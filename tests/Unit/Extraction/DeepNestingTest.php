<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;

beforeEach(function () {
    $this->extractor = new ContentExtractor;
});

// ── Bard inside replicator ────────────────────────────────────────────────────

it('extracts bard field nested inside a replicator set', function () {
    $data = [
        'blocks' => [
            [
                'type' => 'article',
                'title' => 'Section Title',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body text']]],
                ],
            ],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'article' => [
                    'fields' => [
                        'title' => ['type' => 'text'],
                        'content' => ['type' => 'bard'],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('blocks.0.title');
    expect($units[0]->text)->toBe('Section Title');
    expect($units[1]->path)->toBe('blocks.0.content.body');
    expect($units[1]->format)->toBe(TranslationFormat::Html);
    expect($units[1]->text)->toBe('Body text');
});

it('extracts bard fields from multiple replicator sets', function () {
    $data = [
        'blocks' => [
            [
                'type' => 'article',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First article']]],
                ],
            ],
            [
                'type' => 'article',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second article']]],
                ],
            ],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'article' => [
                    'fields' => [
                        'content' => ['type' => 'bard'],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('blocks.0.content.body');
    expect($units[0]->text)->toBe('First article');
    expect($units[1]->path)->toBe('blocks.1.content.body');
    expect($units[1]->text)->toBe('Second article');
});

// ── Replicator inside bard set ────────────────────────────────────────────────

it('extracts replicator fields nested inside a bard set', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Intro']]],
            [
                'type' => 'set',
                'attrs' => [
                    'values' => [
                        'type' => 'card_group',
                        'cards' => [
                            ['type' => 'card', 'title' => 'Card 1'],
                            ['type' => 'card', 'title' => 'Card 2'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'card_group' => [
                    'fields' => [
                        'cards' => [
                            'type' => 'replicator',
                            'sets' => [
                                'card' => ['fields' => ['title' => ['type' => 'text']]],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    $bodyUnit = collect($units)->first(fn ($u) => $u->format === TranslationFormat::Html);
    expect($bodyUnit)->not->toBeNull();
    expect($bodyUnit->path)->toBe('content.body');
    expect($bodyUnit->text)->toBe("Intro\n\n<x-set-0/>");

    $card1 = collect($units)->first(fn ($u) => $u->path === 'content.1.attrs.values.cards.0.title');
    $card2 = collect($units)->first(fn ($u) => $u->path === 'content.1.attrs.values.cards.1.title');
    expect($card1)->not->toBeNull();
    expect($card2)->not->toBeNull();
    expect($card1->text)->toBe('Card 1');
    expect($card2->text)->toBe('Card 2');
});

it('extracts grid fields nested inside a bard set', function () {
    $data = [
        'content' => [
            [
                'type' => 'set',
                'attrs' => [
                    'values' => [
                        'type' => 'links_set',
                        'links' => [
                            ['label' => 'Home', 'url' => '/'],
                            ['label' => 'About', 'url' => '/about'],
                        ],
                    ],
                ],
            ],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Footer']]],
        ],
    ];
    $fields = [
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'links_set' => [
                    'fields' => [
                        'links' => [
                            'type' => 'grid',
                            'fields' => [
                                'label' => ['type' => 'text'],
                                'url' => ['type' => 'text', 'translatable' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    $bodyUnit = collect($units)->first(fn ($u) => $u->format === TranslationFormat::Html);
    expect($bodyUnit->text)->toBe("<x-set-0/>\n\nFooter");

    $label1 = collect($units)->first(fn ($u) => $u->path === 'content.0.attrs.values.links.0.label');
    $label2 = collect($units)->first(fn ($u) => $u->path === 'content.0.attrs.values.links.1.label');
    expect($label1->text)->toBe('Home');
    expect($label2->text)->toBe('About');

    // URL is translatable: false — should be skipped
    $url = collect($units)->first(fn ($u) => str_contains($u->path, '.url'));
    expect($url)->toBeNull();
});

// ── Bard inside replicator inside bard set ────────────────────────────────────

it('extracts bard inside replicator inside bard set (three levels deep)', function () {
    $data = [
        'outer_content' => [
            [
                'type' => 'set',
                'attrs' => [
                    'values' => [
                        'type' => 'section',
                        'blocks' => [
                            [
                                'type' => 'article',
                                'inner_content' => [
                                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Deep nested text']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $fields = [
        'outer_content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'section' => [
                    'fields' => [
                        'blocks' => [
                            'type' => 'replicator',
                            'sets' => [
                                'article' => [
                                    'fields' => [
                                        'inner_content' => ['type' => 'bard'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    $deepUnit = collect($units)->first(fn ($u) => $u->format === TranslationFormat::Html);
    expect($deepUnit)->not->toBeNull();
    expect($deepUnit->path)->toBe('outer_content.0.attrs.values.blocks.0.inner_content.body');
    expect($deepUnit->text)->toBe('Deep nested text');
});

it('handles bard with sets inside a replicator inside a bard set', function () {
    $data = [
        'page_content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Page intro']]],
            [
                'type' => 'set',
                'attrs' => [
                    'values' => [
                        'type' => 'section',
                        'label' => 'Section label',
                        'items' => [
                            [
                                'type' => 'item',
                                'item_body' => [
                                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item text']]],
                                    ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'A quote']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $fields = [
        'page_content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'section' => [
                    'fields' => [
                        'label' => ['type' => 'text'],
                        'items' => [
                            'type' => 'replicator',
                            'sets' => [
                                'item' => [
                                    'fields' => [
                                        'item_body' => [
                                            'type' => 'bard',
                                            'sets' => [
                                                'quote' => ['fields' => ['text' => ['type' => 'text']]],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $units = $this->extractor->extract($data, $fields);

    // Outer bard body (contains intro paragraph + set placeholder)
    $outerBody = collect($units)->first(fn ($u) => $u->path === 'page_content.body');
    expect($outerBody)->not->toBeNull();
    expect($outerBody->text)->toBe("Page intro\n\n<x-set-0/>");

    // Section label from the outer bard's set
    $sectionLabel = collect($units)->first(fn ($u) => $u->path === 'page_content.1.attrs.values.label');
    expect($sectionLabel)->not->toBeNull();
    expect($sectionLabel->text)->toBe('Section label');

    // Inner bard body (contains item text + quote set placeholder)
    $innerBodyPath = 'page_content.1.attrs.values.items.0.item_body.body';
    $innerBody = collect($units)->first(fn ($u) => $u->path === $innerBodyPath);
    expect($innerBody)->not->toBeNull();
    expect($innerBody->text)->toBe("Item text\n\n<x-set-0/>");

    // Quote text from inner bard's set
    $quotePath = 'page_content.1.attrs.values.items.0.item_body.1.attrs.values.text';
    $quoteUnit = collect($units)->first(fn ($u) => $u->path === $quotePath);
    expect($quoteUnit)->not->toBeNull();
    expect($quoteUnit->text)->toBe('A quote');
});
