<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Data\TranslationFormat;
use ElSchneider\MagicTranslator\Data\TranslationUnit;
use ElSchneider\MagicTranslator\Extraction\ContentExtractor;
use ElSchneider\MagicTranslator\Reassembly\ContentReassembler;

beforeEach(function () {
    $this->reassembler = new ContentReassembler;
});

// ── Plain / Markdown ──────────────────────────────────────────────────────────

it('reassembles plain text fields', function () {
    $originalData = ['title' => 'Hello', 'meta' => 'Description'];
    $units = [
        (new TranslationUnit('title', 'Hello', TranslationFormat::Plain))->withTranslation('Bonjour'),
        (new TranslationUnit('meta', 'Description', TranslationFormat::Plain))->withTranslation('La description'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['title'])->toBe('Bonjour');
    expect($result['meta'])->toBe('La description');
});

it('reassembles markdown fields', function () {
    $originalData = ['body' => '**Hello**'];
    $units = [
        (new TranslationUnit('body', '**Hello**', TranslationFormat::Markdown))->withTranslation('**Bonjour**'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['body'])->toBe('**Bonjour**');
});

it('skips units with null translatedText', function () {
    $originalData = ['title' => 'Hello'];
    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain), // no translation
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['title'])->toBe('Hello'); // unchanged
});

it('skips plain and markdown units whose paths no longer exist', function () {
    $originalData = [
        'title' => 'Hello',
        'blocks' => [
            ['type' => 'image', 'caption' => 'Existing caption'],
        ],
    ];
    $units = [
        // stale top-level path
        (new TranslationUnit('subtitle', 'Old subtitle', TranslationFormat::Plain))
            ->withTranslation('Sous-titre'),
        // stale nested path (set/row removed since extraction)
        (new TranslationUnit('blocks.5.caption', 'Old caption', TranslationFormat::Plain))
            ->withTranslation('Ancienne légende'),
        // real path still updates
        (new TranslationUnit('title', 'Hello', TranslationFormat::Markdown))
            ->withTranslation('Bonjour'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['title'])->toBe('Bonjour');
    expect($result)->not->toHaveKey('subtitle');
    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['caption'])->toBe('Existing caption');
});

// ── Replicator ────────────────────────────────────────────────────────────────

it('reassembles replicator fields by dot-path', function () {
    $originalData = [
        'blocks' => [
            ['type' => 'text', 'body' => 'Hello'],
            ['type' => 'image', 'caption' => 'A photo'],
        ],
    ];
    $units = [
        (new TranslationUnit('blocks.0.body', 'Hello', TranslationFormat::Plain))->withTranslation('Bonjour'),
        (new TranslationUnit('blocks.1.caption', 'A photo', TranslationFormat::Plain))->withTranslation('Une photo'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['blocks'][0]['body'])->toBe('Bonjour');
    expect($result['blocks'][1]['caption'])->toBe('Une photo');
    // type fields preserved
    expect($result['blocks'][0]['type'])->toBe('text');
    expect($result['blocks'][1]['type'])->toBe('image');
});

it('reassembles nested replicator fields', function () {
    $originalData = [
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
    $units = [
        (new TranslationUnit('outer.0.title', 'Section title', TranslationFormat::Plain))->withTranslation('Titre de section'),
        (new TranslationUnit('outer.0.inner.0.body', 'Nested paragraph', TranslationFormat::Plain))->withTranslation('Paragraphe imbriqué'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['outer'][0]['title'])->toBe('Titre de section');
    expect($result['outer'][0]['inner'][0]['body'])->toBe('Paragraphe imbriqué');
    expect($result['outer'][0]['type'])->toBe('section');
});

// ── Grid ──────────────────────────────────────────────────────────────────────

it('reassembles grid fields by dot-path', function () {
    $originalData = [
        'links' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'About', 'url' => '/about'],
        ],
    ];
    $units = [
        (new TranslationUnit('links.0.label', 'Home', TranslationFormat::Plain))->withTranslation('Accueil'),
        (new TranslationUnit('links.1.label', 'About', TranslationFormat::Plain))->withTranslation('À propos'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['links'][0]['label'])->toBe('Accueil');
    expect($result['links'][1]['label'])->toBe('À propos');
    // url preserved untouched
    expect($result['links'][0]['url'])->toBe('/');
    expect($result['links'][1]['url'])->toBe('/about');
});

// ── Table ─────────────────────────────────────────────────────────────────────

it('reassembles table cells by dot-path', function () {
    $originalData = [
        'my_table' => [
            ['cells' => ['Name', 'Role']],
            ['cells' => ['Alice', 'Developer']],
        ],
    ];
    $units = [
        (new TranslationUnit('my_table.0.cells.0', 'Name', TranslationFormat::Plain))->withTranslation('Nom'),
        (new TranslationUnit('my_table.0.cells.1', 'Role', TranslationFormat::Plain))->withTranslation('Rôle'),
        (new TranslationUnit('my_table.1.cells.0', 'Alice', TranslationFormat::Plain))->withTranslation('Alice'),
        (new TranslationUnit('my_table.1.cells.1', 'Developer', TranslationFormat::Plain))->withTranslation('Développeuse'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['my_table'][0]['cells'][0])->toBe('Nom');
    expect($result['my_table'][0]['cells'][1])->toBe('Rôle');
    expect($result['my_table'][1]['cells'][0])->toBe('Alice');
    expect($result['my_table'][1]['cells'][1])->toBe('Développeuse');
});

// ── Bard body ─────────────────────────────────────────────────────────────────

it('reassembles single bard paragraph back into prosemirror', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', 'Hello', TranslationFormat::Html))->withTranslation('Bonjour'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['type'])->toBe('paragraph');
    expect($result['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Bonjour'],
    ]);
});

it('reassembles multiple bard paragraphs back into prosemirror', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'World']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "Hello\n\nWorld", TranslationFormat::Html))->withTranslation("Bonjour\n\nLe monde"),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['type'])->toBe('paragraph');
    expect($result['content'][0]['content'][0]['text'])->toBe('Bonjour');
    expect($result['content'][1]['type'])->toBe('paragraph');
    expect($result['content'][1]['content'][0]['text'])->toBe('Le monde');
});

it('ignores extra bard translated blocks when there are more blocks than original nodes', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "One\n\nTwo", TranslationFormat::Html))
            ->withTranslation("Un\n\nDeux\n\nTrois (extra)"),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'])->toHaveCount(2);
    expect($result['content'][0]['content'][0]['text'])->toBe('Un');
    expect($result['content'][1]['content'][0]['text'])->toBe('Deux');
});

it('preserves remaining original bard blocks when translation has fewer blocks than original', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Three']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "One\n\nTwo\n\nThree", TranslationFormat::Html))
            ->withTranslation("Un\n\nDeux"),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['content'][0]['text'])->toBe('Un');
    expect($result['content'][1]['content'][0]['text'])->toBe('Deux');
    // no translated block left, so original text remains unchanged
    expect($result['content'][2]['content'][0]['text'])->toBe('Three');
});

it('reassembles heading and paragraph bard nodes', function () {
    $originalData = [
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Section Title']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body text']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "Section Title\n\nBody text", TranslationFormat::Html))->withTranslation("Titre de section\n\nTexte du corps"),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    // Heading attributes preserved
    expect($result['content'][0]['type'])->toBe('heading');
    expect($result['content'][0]['attrs'])->toBe(['level' => 2]);
    expect($result['content'][0]['content'][0]['text'])->toBe('Titre de section');
    // Paragraph translated
    expect($result['content'][1]['content'][0]['text'])->toBe('Texte du corps');
});

it('reassembles bard body with inline marks', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
            ]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', 'Hello <b>world</b>', TranslationFormat::Html))
            ->withTranslation('Bonjour <b>monde</b>'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Bonjour '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'monde'],
    ]);
});

it('reassembles bard body with link marks', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]], 'text' => 'click here'],
            ]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', '<a href="https://example.com">click here</a>', TranslationFormat::Html))
            ->withTranslation('<a href="https://example.com">cliquez ici</a>'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['content'])->toBe([
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]], 'text' => 'cliquez ici'],
    ]);
});

it('reassembles bard body with custom marks via markMap', function () {
    $markMap = [0 => ['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]];
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]], 'text' => 'styled'],
            ]],
        ],
    ];
    $unit = new TranslationUnit(
        path: 'content.body',
        text: '<span data-mark-0>styled</span>',
        format: TranslationFormat::Html,
        translatedText: '<span data-mark-0>stylisé</span>',
        markMap: $markMap,
    );

    $result = $this->reassembler->reassemble($originalData, [$unit], []);

    expect($result['content'][0]['content'])->toBe([
        ['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]], 'text' => 'stylisé'],
    ]);
});

it('preserves bard nodes with empty content arrays unchanged', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => []],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Real content']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', 'Real content', TranslationFormat::Html))->withTranslation('Contenu réel'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    // Empty paragraph preserved
    expect($result['content'][0]['content'])->toBe([]);
    // Real paragraph translated
    expect($result['content'][1]['content'][0]['text'])->toBe('Contenu réel');
});

it('preserves bard nodes without content key unchanged', function () {
    $originalData = [
        'content' => [
            ['type' => 'horizontal_rule'],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After rule']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', 'After rule', TranslationFormat::Html))->withTranslation('Après la règle'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['type'])->toBe('horizontal_rule');
    expect($result['content'][0])->not->toHaveKey('content');
    expect($result['content'][1]['content'][0]['text'])->toBe('Après la règle');
});

it('preserves custom bard nodes that have no content array', function () {
    $originalData = [
        'content' => [
            ['type' => 'btsDiv', 'attrs' => ['class' => 'callout']],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Inside']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', 'Inside', TranslationFormat::Html))->withTranslation('À l’intérieur'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0])->toBe(['type' => 'btsDiv', 'attrs' => ['class' => 'callout']]);
    expect($result['content'][1]['content'][0]['text'])->toBe('À l’intérieur');
});

// ── Bard with sets ────────────────────────────────────────────────────────────

it('reassembles bard with sets — body text and set fields separately, set structure preserved', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'A photo']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'World']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "Hello\n\n<x-set-0/>\n\nWorld", TranslationFormat::Html))
            ->withTranslation("Bonjour\n\n<x-set-0/>\n\nLe monde"),
        (new TranslationUnit('content.1.attrs.values.caption', 'A photo', TranslationFormat::Plain))
            ->withTranslation('Une photo'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    // Paragraph 0 translated
    expect($result['content'][0]['type'])->toBe('paragraph');
    expect($result['content'][0]['content'][0]['text'])->toBe('Bonjour');
    // Set node preserved with translated caption
    expect($result['content'][1]['type'])->toBe('set');
    expect($result['content'][1]['attrs']['values']['type'])->toBe('image');
    expect($result['content'][1]['attrs']['values']['caption'])->toBe('Une photo');
    // Paragraph 2 translated
    expect($result['content'][2]['type'])->toBe('paragraph');
    expect($result['content'][2]['content'][0]['text'])->toBe('Le monde');
});

it('reassembles bard with set at start — set preserved, text after translated', function () {
    $originalData = [
        'content' => [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'Photo']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Description']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "<x-set-0/>\n\nDescription", TranslationFormat::Html))
            ->withTranslation("<x-set-0/>\n\nDescription en français"),
        (new TranslationUnit('content.0.attrs.values.caption', 'Photo', TranslationFormat::Plain))
            ->withTranslation('Photographie'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['type'])->toBe('set');
    expect($result['content'][0]['attrs']['values']['caption'])->toBe('Photographie');
    expect($result['content'][1]['content'][0]['text'])->toBe('Description en français');
});

it('reassembles bard with multiple sets — all placeholders preserved, all fields translated', function () {
    $originalData = [
        'content' => [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'First']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Middle']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'Second']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "<x-set-0/>\n\nMiddle\n\n<x-set-1/>", TranslationFormat::Html))
            ->withTranslation("<x-set-0/>\n\nMilieu\n\n<x-set-1/>"),
        (new TranslationUnit('content.0.attrs.values.caption', 'First', TranslationFormat::Plain))
            ->withTranslation('Premier'),
        (new TranslationUnit('content.2.attrs.values.caption', 'Second', TranslationFormat::Plain))
            ->withTranslation('Deuxième'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['type'])->toBe('set');
    expect($result['content'][0]['attrs']['values']['caption'])->toBe('Premier');
    expect($result['content'][1]['content'][0]['text'])->toBe('Milieu');
    expect($result['content'][2]['type'])->toBe('set');
    expect($result['content'][2]['attrs']['values']['caption'])->toBe('Deuxième');
});

// ── Non-translatable data preserved ──────────────────────────────────────────

it('preserves non-translatable data untouched', function () {
    $originalData = [
        'title' => 'Hello',
        'status' => 'published',
        'featured_image' => 'photo.jpg',
        'view_count' => 42,
        'tags' => ['news', 'tech'],
        'slug' => 'hello-world',
    ];
    $units = [
        (new TranslationUnit('title', 'Hello', TranslationFormat::Plain))->withTranslation('Bonjour'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['title'])->toBe('Bonjour');
    expect($result['status'])->toBe('published');
    expect($result['featured_image'])->toBe('photo.jpg');
    expect($result['view_count'])->toBe(42);
    expect($result['tags'])->toBe(['news', 'tech']);
    expect($result['slug'])->toBe('hello-world');
});

it('does not mutate the original data array', function () {
    $originalData = ['title' => 'Hello'];
    $units = [
        (new TranslationUnit('title', 'Hello', TranslationFormat::Plain))->withTranslation('Bonjour'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['title'])->toBe('Bonjour');
    expect($originalData['title'])->toBe('Hello'); // original unchanged
});

// ── Deeply nested reassembly ──────────────────────────────────────────────────

it('reassembles bard field nested inside a replicator set', function () {
    $originalData = [
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
    $units = [
        (new TranslationUnit('blocks.0.title', 'Section Title', TranslationFormat::Plain))
            ->withTranslation('Titre de section'),
        (new TranslationUnit('blocks.0.content.body', 'Body text', TranslationFormat::Html))
            ->withTranslation('Texte du corps'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['blocks'][0]['type'])->toBe('article');
    expect($result['blocks'][0]['title'])->toBe('Titre de section');
    expect($result['blocks'][0]['content'][0]['type'])->toBe('paragraph');
    expect($result['blocks'][0]['content'][0]['content'][0]['text'])->toBe('Texte du corps');
});

it('reassembles bard fields from multiple replicator sets', function () {
    $originalData = [
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
    $units = [
        (new TranslationUnit('blocks.0.content.body', 'First article', TranslationFormat::Html))
            ->withTranslation('Premier article'),
        (new TranslationUnit('blocks.1.content.body', 'Second article', TranslationFormat::Html))
            ->withTranslation('Deuxième article'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['blocks'][0]['content'][0]['content'][0]['text'])->toBe('Premier article');
    expect($result['blocks'][1]['content'][0]['content'][0]['text'])->toBe('Deuxième article');
});

it('reassembles replicator fields nested inside a bard set', function () {
    $originalData = [
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
    $units = [
        (new TranslationUnit('content.body', "Intro\n\n<x-set-0/>", TranslationFormat::Html))
            ->withTranslation("Introduction\n\n<x-set-0/>"),
        (new TranslationUnit('content.1.attrs.values.cards.0.title', 'Card 1', TranslationFormat::Plain))
            ->withTranslation('Carte 1'),
        (new TranslationUnit('content.1.attrs.values.cards.1.title', 'Card 2', TranslationFormat::Plain))
            ->withTranslation('Carte 2'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['content'][0]['text'])->toBe('Introduction');
    expect($result['content'][1]['type'])->toBe('set');
    expect($result['content'][1]['attrs']['values']['cards'][0]['title'])->toBe('Carte 1');
    expect($result['content'][1]['attrs']['values']['cards'][1]['title'])->toBe('Carte 2');
});

it('reassembles bard inside replicator inside bard set (three levels deep)', function () {
    $originalData = [
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
    $units = [
        (new TranslationUnit(
            'outer_content.0.attrs.values.blocks.0.inner_content.body',
            'Deep nested text',
            TranslationFormat::Html,
        ))->withTranslation('Texte profondément imbriqué'),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    $innerParagraph = $result['outer_content'][0]['attrs']['values']['blocks'][0]['inner_content'][0];
    expect($innerParagraph['type'])->toBe('paragraph');
    expect($innerParagraph['content'][0]['text'])->toBe('Texte profondément imbriqué');
    // Set structure preserved
    expect($result['outer_content'][0]['type'])->toBe('set');
});

// ── Blockquote / list container nodes ────────────────────────────────────────

it('reassembles prose inside a blockquote container node', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Intro']]],
            ['type' => 'blockquote', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quote line 1']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quote line 2']]],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Outro']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "Intro\n\nQuote line 1\n\nQuote line 2\n\nOutro", TranslationFormat::Html))
            ->withTranslation("Introduction\n\nCitation ligne 1\n\nCitation ligne 2\n\nConclusion"),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['content'][0]['text'])->toBe('Introduction');
    // Blockquote preserved with translated inner paragraphs
    expect($result['content'][1]['type'])->toBe('blockquote');
    expect($result['content'][1]['content'][0]['content'][0]['text'])->toBe('Citation ligne 1');
    expect($result['content'][1]['content'][1]['content'][0]['text'])->toBe('Citation ligne 2');
    expect($result['content'][2]['content'][0]['text'])->toBe('Conclusion');
});

it('reassembles prose inside list nodes', function () {
    $originalData = [
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
    $units = [
        (new TranslationUnit('content.body', "First item\n\nSecond item\n\nAfter list", TranslationFormat::Html))
            ->withTranslation("Premier élément\n\nDeuxième élément\n\nAprès la liste"),
    ];

    $result = $this->reassembler->reassemble($originalData, $units, []);

    expect($result['content'][0]['type'])->toBe('bullet_list');
    expect($result['content'][0]['content'][0]['type'])->toBe('list_item');
    expect($result['content'][0]['content'][0]['content'][0]['content'][0]['text'])->toBe('Premier élément');
    expect($result['content'][0]['content'][1]['content'][0]['content'][0]['text'])->toBe('Deuxième élément');
    expect($result['content'][1]['content'][0]['text'])->toBe('Après la liste');
});

// ── Full round-trip ───────────────────────────────────────────────────────────

it('full round-trip: extract → translate per-unit → reassemble', function () {
    $extractor = new ContentExtractor;

    $data = [
        'title' => 'Hello World',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First paragraph']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second paragraph']]],
        ],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'content' => ['type' => 'bard', 'localizable' => true],
    ];

    // Extract
    $units = $extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('title');
    expect($units[1]->path)->toBe('content.body');

    // Translate each unit by replacing text content
    $translatedUnits = [
        $units[0]->withTranslation('Bonjour le monde'),                      // title
        $units[1]->withTranslation("Premier paragraphe\n\nDeuxième paragraphe"), // bard body
    ];

    // Reassemble
    $result = $this->reassembler->reassemble($data, $translatedUnits, $fields);

    expect($result['title'])->toBe('Bonjour le monde');
    expect($result['content'][0]['type'])->toBe('paragraph');
    expect($result['content'][0]['content'][0]['text'])->toBe('Premier paragraphe');
    expect($result['content'][1]['type'])->toBe('paragraph');
    expect($result['content'][1]['content'][0]['text'])->toBe('Deuxième paragraphe');
});

it('full round-trip with bard inline marks preserved through translation', function () {
    $extractor = new ContentExtractor;

    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
            ]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $extractor->extract($data, $fields);

    // units[0].text = "Hello <b>world</b>"
    // Simulated translation: preserve HTML, translate words
    $translatedUnits = [
        $units[0]->withTranslation('Bonjour <b>monde</b>'),
    ];

    $result = $this->reassembler->reassemble($data, $translatedUnits, $fields);

    expect($result['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Bonjour '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'monde'],
    ]);
});

it('full round-trip with bard sets: extract → translate → reassemble → verify structure', function () {
    $extractor = new ContentExtractor;

    $data = [
        'title' => 'My Post',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before set']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'A photo']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After set']]],
        ],
    ];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => [
                'image' => ['fields' => ['caption' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $extractor->extract($data, $fields);

    // 3 units: title, content.body, content.1.attrs.values.caption
    expect($units)->toHaveCount(3);

    // Map each unit to a translated version
    $translations = [
        'title' => 'Mon article',
        'content.body' => "Avant le bloc\n\n<x-set-0/>\n\nAprès le bloc",
        'content.1.attrs.values.caption' => 'Une photo',
    ];

    $translatedUnits = array_map(
        fn (TranslationUnit $u) => $u->withTranslation($translations[$u->path]),
        $units
    );

    $result = $this->reassembler->reassemble($data, $translatedUnits, $fields);

    expect($result['title'])->toBe('Mon article');
    expect($result['content'][0]['content'][0]['text'])->toBe('Avant le bloc');
    expect($result['content'][1]['type'])->toBe('set');
    expect($result['content'][1]['attrs']['values']['type'])->toBe('image');
    expect($result['content'][1]['attrs']['values']['caption'])->toBe('Une photo');
    expect($result['content'][2]['content'][0]['text'])->toBe('Après le bloc');
});

it('full round-trip with replicator: extract → translate → reassemble', function () {
    $extractor = new ContentExtractor;

    $data = [
        'blocks' => [
            ['type' => 'text', 'body' => 'Hello world'],
            ['type' => 'image', 'src' => 'photo.jpg', 'caption' => 'Nice photo'],
        ],
    ];
    $fields = [
        'blocks' => [
            'type' => 'replicator',
            'localizable' => true,
            'sets' => [
                'text' => ['fields' => ['body' => ['type' => 'text']]],
                'image' => ['fields' => [
                    'src' => ['type' => 'assets'],
                    'caption' => ['type' => 'text'],
                ]],
            ],
        ],
    ];

    $units = $extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);

    $translatedUnits = [
        $units[0]->withTranslation('Bonjour le monde'),
        $units[1]->withTranslation('Belle photo'),
    ];

    $result = $this->reassembler->reassemble($data, $translatedUnits, $fields);

    expect($result['blocks'][0]['body'])->toBe('Bonjour le monde');
    expect($result['blocks'][0]['type'])->toBe('text');
    expect($result['blocks'][1]['caption'])->toBe('Belle photo');
    expect($result['blocks'][1]['type'])->toBe('image');
    // src preserved (assets field, not translated)
    expect($result['blocks'][1]['src'])->toBe('photo.jpg');
});
