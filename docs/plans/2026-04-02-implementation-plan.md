# Content Translator Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a Statamic addon that translates entry content across multi-site localizations using LLMs (Prism) or DeepL, with async job processing and a dialog-based UI.

**Architecture:** Flatten → Batch Translate → Reassemble. Recursively extract translatable text from entry data into flat `TranslationUnit` list, send all units to a translation service in one batch, reassemble translated units back into the original data structure. One queued job per entry-locale pair.

**Tech Stack:** PHP 8.2+, Laravel 11/12, Statamic 5/6, Pest, Prism (`prism-php/prism`), DeepL SDK (`deeplcom/deepl-php`), Vue 2/3, TypeScript, Vite.

**Design doc:** `docs/plans/2026-04-02-content-translator-design.md`

---

## Task 1: Project Scaffolding & Test Infrastructure

**Files:**
- Modify: `composer.json`
- Create: `config/content-translator.php`
- Create: `tests/TestCase.php`
- Create: `tests/Pest.php`
- Create: `tests/StatamicTestHelpers.php`
- Create: `pint.json`
- Create: `vite.config.js`
- Create: `package.json`
- Create: `tsconfig.json`
- Modify: `src/ServiceProvider.php`

**Step 1: Update composer.json with dependencies**

Add `prism-php/prism` and `deeplcom/deepl-php` to require. Reference auto-alt-text's structure for dev dependencies.

```json
{
    "require": {
        "statamic/cms": "^5.0 || ^6.0",
        "prism-php/prism": "^1.0",
        "deeplcom/deepl-php": "^1.0"
    }
}
```

**Step 2: Create the config file**

Create `config/content-translator.php` with the full config structure from the design doc — collections, exclude_blueprints, service selection, prism config, deepl config, queue, max_units_per_request, log_completions.

**Step 3: Create test infrastructure**

Model after auto-alt-text's test setup:
- `tests/TestCase.php` — extends `AddonTestCase`, uses `PreventsSavingStacheItemsToDisk`, sets pro edition, configures multi-site.
- `tests/Pest.php` — binds TestCase to Feature/Unit directories.
- `tests/StatamicTestHelpers.php` — trait with `createTestUser()`, `createTestEntry()`, `createMultiSiteSetup()` helpers. Must set up at least 2 sites (en, fr) and a collection with a blueprint containing various field types.

**Step 4: Create frontend build config**

- `package.json` with `vite`, `laravel-vite-plugin`, `vue` (both 2 and 3 as optional peers), `typescript`, `prettier`, `husky`.
- `vite.config.js` — two entry points: `resources/js/v5/addon.ts` and `resources/js/v6/addon.ts`.
- `tsconfig.json` — standard TypeScript config.
- `pint.json` — match auto-alt-text's pint config.
- `.prettierrc` — consistent code formatting for JS/TS/Vue files.

**Step 4a: Set up Husky with pre-commit hooks**

```bash
npx husky init
```

Configure `.husky/pre-commit`:

```bash
npx prettier --write --cache "resources/**/*.{js,ts,vue}"
./vendor/bin/pint
```

This ensures all JS/TS/Vue files are formatted by Prettier and all PHP files are formatted by Pint on every commit.

**Step 5: Stub the ServiceProvider**

Update `src/ServiceProvider.php` to extend `AddonServiceProvider` with config registration, translation loading, and the `supportsInertia()` method. Just the skeleton — event listeners and service bindings come in later tasks.

**Step 6: Run `composer install` and verify tests run**

```bash
composer install
./vendor/bin/pest
```

Expected: 0 tests, 0 assertions (green).

**Step 7: Commit**

```bash
git add -A
git commit -m "feat: project scaffolding with config, test infrastructure, and build setup"
```

---

## Task 2: TranslationUnit Value Object

**Files:**
- Create: `src/Data/TranslationUnit.php`
- Create: `src/Data/TranslationFormat.php`
- Create: `tests/Unit/TranslationUnitTest.php`

**Step 1: Write tests for TranslationUnit**

```php
it('creates a plain text unit', function () {
    $unit = new TranslationUnit(path: 'title', text: 'Hello', format: TranslationFormat::Plain);
    expect($unit->path)->toBe('title');
    expect($unit->text)->toBe('Hello');
    expect($unit->format)->toBe(TranslationFormat::Plain);
    expect($unit->translatedText)->toBeNull();
    expect($unit->markMap)->toBe([]);
});

it('creates an html unit with mark map', function () {
    $markMap = [0 => ['type' => 'bold']];
    $unit = new TranslationUnit(path: 'content.0.content', text: '<b>Hello</b>', format: TranslationFormat::Html, markMap: $markMap);
    expect($unit->format)->toBe(TranslationFormat::Html);
    expect($unit->markMap)->toBe($markMap);
});

it('creates a markdown unit', function () {
    $unit = new TranslationUnit(path: 'body', text: '**Hello**', format: TranslationFormat::Markdown);
    expect($unit->format)->toBe(TranslationFormat::Markdown);
});

it('can set translated text', function () {
    $unit = new TranslationUnit(path: 'title', text: 'Hello', format: TranslationFormat::Plain);
    $translated = $unit->withTranslation('Bonjour');
    expect($translated->translatedText)->toBe('Bonjour');
    expect($translated->text)->toBe('Hello'); // original unchanged
    expect($unit->translatedText)->toBeNull(); // immutable
});
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Unit/TranslationUnitTest.php
```

**Step 3: Implement TranslationFormat enum and TranslationUnit**

```php
// src/Data/TranslationFormat.php
enum TranslationFormat: string
{
    case Plain = 'plain';
    case Html = 'html';
    case Markdown = 'markdown';
}

// src/Data/TranslationUnit.php
final readonly class TranslationUnit
{
    public function __construct(
        public string $path,
        public string $text,
        public TranslationFormat $format,
        public ?string $translatedText = null,
        public array $markMap = [],
    ) {}

    public function withTranslation(string $translatedText): self
    {
        return new self(
            path: $this->path,
            text: $this->text,
            format: $this->format,
            translatedText: $translatedText,
            markMap: $this->markMap,
        );
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Unit/TranslationUnitTest.php
```

**Step 5: Commit**

```bash
git add -A
git commit -m "feat: add TranslationUnit value object and TranslationFormat enum"
```

---

## Task 3: Content Extractor — Tier 1 (Flat Text Fields)

**Files:**
- Create: `src/Extraction/ContentExtractor.php`
- Create: `src/Extraction/FieldClassifier.php`
- Create: `tests/Unit/Extraction/ContentExtractorTest.php`
- Create: `tests/Unit/Extraction/FieldClassifierTest.php`

**Step 1: Write FieldClassifier tests**

Test that field types are classified correctly:
- `text`, `textarea`, `markdown` → translatable (tier 1)
- `replicator`, `grid` → structural (tier 2)
- `bard` → bard (tier 3)
- `table` → structural (tier 2)
- `assets`, `toggle`, `integer`, `select`, etc. → skip (tier 4)
- `link` → borderline (translatable)
- Fields with `translatable: false` in config → skip regardless of type
- Fields with `localizable: false` → skip regardless of type

**Step 2: Write ContentExtractor tests for tier 1**

Test extraction from a flat entry with simple fields:

```php
it('extracts text fields', function () {
    $data = ['title' => 'My Post', 'meta' => 'Description'];
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'meta' => ['type' => 'textarea', 'localizable' => true],
    ];

    $units = $extractor->extract($data, $fields);

    expect($units)->toHaveCount(2);
    expect($units[0]->path)->toBe('title');
    expect($units[0]->text)->toBe('My Post');
    expect($units[0]->format)->toBe(TranslationFormat::Plain);
});

it('skips non-localizable fields', function () { ... });
it('skips fields with translatable false', function () { ... });
it('skips non-text field types', function () { ... });
it('skips empty/null values', function () { ... });
it('extracts markdown fields with markdown format', function () { ... });
```

**Step 3: Implement FieldClassifier**

An enum or class that maps fieldtype strings to tiers. Simple match statement.

**Step 4: Implement ContentExtractor (tier 1 only)**

The extractor takes entry data (array) and a field definitions array. For tier 1 fields, it creates `TranslationUnit` objects directly. For tier 2/3, it will delegate (implemented in later tasks) — for now, skip them.

**Step 5: Run tests, verify pass**

**Step 6: Commit**

```bash
git commit -m "feat: content extractor with tier 1 flat text field support"
```

---

## Task 4: Content Extractor — Tier 2 (Replicator, Grid, Table)

**Files:**
- Modify: `src/Extraction/ContentExtractor.php`
- Create: `tests/Unit/Extraction/ReplicatorExtractionTest.php`
- Create: `tests/Unit/Extraction/GridExtractionTest.php`
- Create: `tests/Unit/Extraction/TableExtractionTest.php`
- Create: `tests/__fixtures__/` — fixture data files

**Step 1: Write replicator extraction tests**

```php
it('extracts text from replicator sets', function () {
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
                'text' => ['fields' => ['body' => ['type' => 'markdown']]],
                'image' => ['fields' => ['src' => ['type' => 'assets'], 'caption' => ['type' => 'text']]],
                'quote' => ['fields' => ['text' => ['type' => 'text'], 'cite' => ['type' => 'text']]],
            ],
        ],
    ];

    $units = $extractor->extract($data, $fields);

    expect($units)->toHaveCount(4); // body, caption, text, cite
    expect($units[0]->path)->toBe('blocks.0.body');
    expect($units[1]->path)->toBe('blocks.1.caption');
});

it('handles nested replicator inside replicator', function () { ... });
```

**Step 2: Write grid extraction tests**

```php
it('extracts text from grid rows', function () {
    $data = [
        'links' => [
            ['url' => 'https://example.com', 'label' => 'Example'],
            ['url' => 'https://other.com', 'label' => 'Other'],
        ],
    ];
    // url is type 'text' but has input_type 'url' — still translatable? No, label is text.
    // Actually url content shouldn't be translated. We need to handle this.
    // For now: extract all text fields, users opt out with translatable: false.
});
```

**Step 3: Write table extraction tests**

```php
it('extracts text from table cells', function () {
    $data = [
        'my_table' => [
            ['cells' => ['Name', 'Role']],
            ['cells' => ['Alice', 'Developer']],
        ],
    ];
    $fields = ['my_table' => ['type' => 'table', 'localizable' => true]];

    $units = $extractor->extract($data, $fields);
    expect($units)->toHaveCount(4);
    expect($units[0]->path)->toBe('my_table.0.cells.0');
    expect($units[0]->text)->toBe('Name');
});
```

**Step 4: Implement recursive extraction for replicator, grid, table**

Extend `ContentExtractor` to recurse into structural containers. The key is looking up each set's field definitions from the blueprint config to know the type of each nested field.

**Step 5: Run all tests, verify pass**

**Step 6: Commit**

```bash
git commit -m "feat: content extractor tier 2 — replicator, grid, table support"
```

---

## Task 5: Bard Content Serializer (ProseMirror → HTML-Tagged String)

**Files:**
- Create: `src/Extraction/BardSerializer.php`
- Create: `tests/Unit/Extraction/BardSerializerTest.php`
- Create: `tests/__fixtures__/bard/` — ProseMirror JSON fixtures

**Step 1: Create ProseMirror fixtures**

Create JSON fixture files for common Bard structures:
- Simple paragraph with plain text
- Paragraph with inline marks (bold, italic, link)
- Multiple paragraphs
- Paragraphs with interlaced sets
- Custom marks (btsSpan-like)
- Headings
- Lists (ordered, unordered)
- Nested marks (bold inside italic)

**Step 2: Write serializer tests**

```php
it('serializes a plain paragraph', function () {
    $content = [['type' => 'text', 'text' => 'Hello world']];
    $result = $serializer->serialize($content);
    expect($result->text)->toBe('Hello world');
    expect($result->markMap)->toBe([]);
});

it('serializes inline bold marks as html tags', function () {
    $content = [
        ['type' => 'text', 'text' => 'Hello '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
    ];
    $result = $serializer->serialize($content);
    expect($result->text)->toBe('Hello <b>world</b>');
});

it('serializes inline italic marks', function () { ... });

it('serializes link marks with href', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]], 'text' => 'click here'],
    ];
    $result = $serializer->serialize($content);
    expect($result->text)->toBe('<a href="https://example.com">click here</a>');
});

it('serializes nested marks', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'italic']], 'text' => 'strong emphasis'],
    ];
    $result = $serializer->serialize($content);
    expect($result->text)->toBe('<b><i>strong emphasis</i></b>');
});

it('serializes custom marks with indexed placeholders', function () {
    $content = [
        ['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]], 'text' => 'styled'],
    ];
    $result = $serializer->serialize($content);
    expect($result->text)->toBe('<span data-mark-0>styled</span>');
    expect($result->markMap[0])->toBe(['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]);
});
```

**Step 3: Implement BardSerializer**

Maps known ProseMirror marks to HTML tags:
- `bold` → `<b>`
- `italic` → `<i>`
- `underline` → `<u>`
- `strike` → `<s>`
- `code` → `<code>`
- `link` → `<a href="...">`
- `superscript` → `<sup>`
- `subscript` → `<sub>`
- Unknown marks → `<span data-mark-N>` with mark map entry

Returns a DTO with the serialized string and the mark map.

**Step 4: Run tests, verify pass**

**Step 5: Commit**

```bash
git commit -m "feat: bard serializer — ProseMirror content to HTML-tagged strings"
```

---

## Task 6: Bard Content Parser (HTML-Tagged String → ProseMirror Content Array)

**Files:**
- Create: `src/Reassembly/BardParser.php`
- Create: `tests/Unit/Reassembly/BardParserTest.php`

**Step 1: Write parser tests — mirror every serializer test**

For each serializer test, write a corresponding parser test that takes the serialized output and produces the original ProseMirror content array:

```php
it('parses plain text', function () {
    $result = $parser->parse('Hello world', []);
    expect($result)->toBe([['type' => 'text', 'text' => 'Hello world']]);
});

it('parses bold tags back to marks', function () {
    $result = $parser->parse('Hello <b>world</b>', []);
    expect($result)->toBe([
        ['type' => 'text', 'text' => 'Hello '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
    ]);
});

it('parses custom mark placeholders using mark map', function () {
    $markMap = [0 => ['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]];
    $result = $parser->parse('<span data-mark-0>styled</span>', $markMap);
    expect($result)->toBe([
        ['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => 'brand']]], 'text' => 'styled'],
    ]);
});

it('round-trips through serializer and parser', function () {
    $original = [ /* complex content with nested marks */ ];
    $serialized = $serializer->serialize($original);
    $parsed = $parser->parse($serialized->text, $serialized->markMap);
    expect($parsed)->toBe($original);
});
```

**Step 2: Implement BardParser**

A simple state-machine HTML fragment parser:
- Maintain a mark stack
- On opening tag: push corresponding mark onto stack
- On closing tag: pop from stack
- On text content: emit a text node with current mark stack as marks
- Map known HTML tags back to ProseMirror mark types (reverse of serializer)
- `<span data-mark-N>` → look up mark map[N]

**Step 3: Run tests, verify pass**

**Step 4: Write round-trip tests with all fixtures from Task 5**

Every ProseMirror fixture should survive serialize → parse round-trip.

**Step 5: Commit**

```bash
git commit -m "feat: bard parser — HTML-tagged strings back to ProseMirror content arrays"
```

---

## Task 7: Content Extractor — Tier 3 (Bard) + Full Integration

**Files:**
- Modify: `src/Extraction/ContentExtractor.php`
- Create: `tests/Unit/Extraction/BardExtractionTest.php`
- Create: `tests/Unit/Extraction/DeepNestingTest.php`

**Step 1: Write Bard extraction tests**

```php
it('extracts bard body text as html units', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second paragraph']]],
        ],
    ];
    $fields = ['content' => ['type' => 'bard', 'localizable' => true]];

    $units = $extractor->extract($data, $fields);

    // Body text collected as single html unit with \n\n separator
    expect($units)->toHaveCount(1);
    expect($units[0]->format)->toBe(TranslationFormat::Html);
    expect($units[0]->text)->toBe("Hello world\n\nSecond paragraph");
});

it('handles bard with interlaced sets', function () {
    $data = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before set']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'A photo']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After set']]],
        ],
    ];

    $units = $extractor->extract($data, $fields);

    // Body text has set placeholder
    $bodyUnit = collect($units)->first(fn ($u) => $u->format === TranslationFormat::Html);
    expect($bodyUnit->text)->toBe("Before set\n\n<x-set-0/>\n\nAfter set");

    // Set fields extracted separately
    $captionUnit = collect($units)->first(fn ($u) => $u->path === 'content.1.attrs.values.caption');
    expect($captionUnit->text)->toBe('A photo');
    expect($captionUnit->format)->toBe(TranslationFormat::Plain);
});

it('handles bard with inline marks in body', function () { ... });
```

**Step 2: Write deep nesting tests**

```php
it('extracts from bard inside replicator', function () { ... });
it('extracts from replicator inside bard set', function () { ... });
it('extracts from bard inside replicator inside bard set', function () { ... });
```

**Step 3: Implement Bard extraction in ContentExtractor**

Walk the ProseMirror array:
- Block nodes (paragraph, heading, list_item, blockquote children): serialize their `content` arrays via `BardSerializer`, collect with `\n\n` separators.
- Set nodes: extract `attrs.values`, look up set fields in blueprint config, recurse.
- Emit one `TranslationUnit` per Bard field for the body text (html format), plus separate units for each set's translatable fields.

**Step 4: Run all extraction tests, verify pass**

**Step 5: Commit**

```bash
git commit -m "feat: content extractor tier 3 — bard field support with deep nesting"
```

---

## Task 8: Content Reassembler

**Files:**
- Create: `src/Reassembly/ContentReassembler.php`
- Create: `tests/Unit/Reassembly/ContentReassemblerTest.php`

**Step 1: Write reassembly tests**

```php
it('reassembles plain text fields', function () {
    $originalData = ['title' => 'Hello', 'meta' => 'Description'];
    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain, 'Bonjour'),
        new TranslationUnit('meta', 'Description', TranslationFormat::Plain, 'La description'),
    ];

    $result = $reassembler->reassemble($originalData, $units, $fields);

    expect($result['title'])->toBe('Bonjour');
    expect($result['meta'])->toBe('La description');
});

it('reassembles replicator fields by path', function () { ... });
it('reassembles grid fields by path', function () { ... });
it('reassembles table cells by path', function () { ... });

it('reassembles bard body text back into prosemirror', function () {
    $originalData = [
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'caption' => 'A photo']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'World']]],
        ],
    ];
    $units = [
        (new TranslationUnit('content.body', "Hello\n\n<x-set-0/>\n\nWorld", TranslationFormat::Html))->withTranslation("Bonjour\n\n<x-set-0/>\n\nLe monde"),
        (new TranslationUnit('content.1.attrs.values.caption', 'A photo', TranslationFormat::Plain))->withTranslation('Une photo'),
    ];

    $result = $reassembler->reassemble($originalData, $units, $fields);

    expect($result['content'][0]['content'][0]['text'])->toBe('Bonjour');
    expect($result['content'][2]['content'][0]['text'])->toBe('Le monde');
    expect($result['content'][1]['attrs']['values']['caption'])->toBe('Une photo');
    // Set structure preserved
    expect($result['content'][1]['type'])->toBe('set');
});

it('preserves non-translatable data untouched', function () { ... });
it('handles deeply nested reassembly', function () { ... });
```

**Step 2: Implement ContentReassembler**

- Deep-clone the original data.
- For `plain` and `markdown` units: use the dot-path to `data_set()` the translated text.
- For `html` (Bard body) units: split translated text by `\n\n`, filter out `<x-set-N/>` placeholders, parse each block's HTML via `BardParser`, replace the `content` array of the corresponding block nodes.

**Step 3: Write full round-trip integration tests**

Extract → mock translate (just prefix each text with "FR: ") → reassemble. Verify the output structure matches the original with translated text values.

**Step 4: Run tests, verify pass**

**Step 5: Commit**

```bash
git commit -m "feat: content reassembler with bard, replicator, grid, table support"
```

---

## Task 9: Translation Service Contract & Factory

**Files:**
- Create: `src/Contracts/TranslationService.php`
- Create: `src/Services/TranslationServiceFactory.php`
- Create: `tests/Unit/Services/TranslationServiceFactoryTest.php`

**Step 1: Create the contract**

```php
namespace ElSchneider\ContentTranslator\Contracts;

use ElSchneider\ContentTranslator\Data\TranslationUnit;

interface TranslationService
{
    /** @param TranslationUnit[] $units @return TranslationUnit[] */
    public function translate(array $units, string $sourceLocale, string $targetLocale): array;
}
```

**Step 2: Write factory tests**

```php
it('creates prism service when configured', function () {
    config(['content-translator.service' => 'prism']);
    $service = app(TranslationServiceFactory::class)->make();
    expect($service)->toBeInstanceOf(PrismTranslationService::class);
});

it('creates deepl service when configured', function () {
    config(['content-translator.service' => 'deepl']);
    $service = app(TranslationServiceFactory::class)->make();
    expect($service)->toBeInstanceOf(DeepLTranslationService::class);
});

it('throws for unknown service', function () {
    config(['content-translator.service' => 'unknown']);
    app(TranslationServiceFactory::class)->make();
})->throws(InvalidArgumentException::class);
```

**Step 3: Implement factory (stub service classes)**

Create stub `PrismTranslationService` and `DeepLTranslationService` implementing the contract (just throw "not implemented" for now). Factory reads config and instantiates.

**Step 4: Run tests, verify pass**

**Step 5: Commit**

```bash
git commit -m "feat: translation service contract and factory"
```

---

## Task 10: Prism Translation Service

**Files:**
- Create: `src/Services/PrismTranslationService.php`
- Create: `src/Services/PromptResolver.php`
- Create: `tests/Unit/Services/PrismTranslationServiceTest.php`
- Create: `tests/Unit/Services/PromptResolverTest.php`
- Create: `resources/views/prompts/system.blade.php`
- Create: `resources/views/prompts/user.blade.php`
- Create: `resources/views/prompts/partials/format-rules.blade.php`

**Step 1: Write PromptResolver tests**

```php
it('resolves default system prompt view', function () { ... });
it('resolves language-specific override when it exists', function () { ... });
it('falls back to default when no override exists', function () { ... });
it('passes locale variables to the view', function () { ... });
it('passes format flags to the view', function () { ... });
```

**Step 2: Implement PromptResolver**

Reads prompt view names from config, checks for language-specific overrides, renders Blade views with locale variables.

**Step 3: Create prompt Blade views**

System prompt, user prompt, and format-rules partial per the design doc.

**Step 4: Write PrismTranslationService tests (mock Prism)**

```php
it('sends all units in a single prompt', function () { ... });
it('uses structured output for response parsing', function () { ... });
it('maps translated text back to units by id', function () { ... });
it('handles empty units array', function () { ... });
it('chunks when max_units_per_request is set', function () { ... });
```

Mock `Prism::text()` to verify the correct prompt is built and structured output schema is correct. Return mock responses.

**Step 5: Implement PrismTranslationService**

- Build the prompt: system prompt (from PromptResolver) + user prompt containing all units as a JSON array `[{id, text}]`.
- Use Prism's structured output to enforce `[{id: string, text: string}]` response schema.
- Map responses back to `TranslationUnit::withTranslation()`.
- Handle chunking if `max_units_per_request` is set.

**Step 6: Run tests, verify pass**

**Step 7: Commit**

```bash
git commit -m "feat: prism translation service with prompt resolver and blade views"
```

---

## Task 11: DeepL Translation Service

**Files:**
- Create: `src/Services/DeepLTranslationService.php`
- Create: `tests/Unit/Services/DeepLTranslationServiceTest.php`

**Step 1: Write DeepL service tests (mock HTTP)**

```php
it('concatenates units with ct-unit delimiters', function () { ... });
it('splits response back by ct-unit tags', function () { ... });
it('uses xml tag handling', function () { ... });
it('applies formality from config', function () { ... });
it('applies per-language formality overrides', function () { ... });
it('maps deepl locale codes correctly', function () { ... });
it('chunks when max_units_per_request is set', function () { ... });
it('handles empty units array', function () { ... });
```

**Step 2: Implement DeepLTranslationService**

- Concatenate units: `<ct-unit id="0">text</ct-unit><ct-unit id="1">text</ct-unit>...`
- Call DeepL API with `tag_handling: "xml"`, formality settings.
- Parse response: extract text content from each `<ct-unit>` tag by id.
- Map translated text back to units.
- Handle locale code mapping (Statamic uses `en`, DeepL uses `EN-US`/`EN-GB`).
- Handle chunking.

**Step 3: Run tests, verify pass**

**Step 4: Commit**

```bash
git commit -m "feat: deepl translation service with xml tag handling and formality support"
```

---

## Task 12: Events

**Files:**
- Create: `src/Events/BeforeEntryTranslation.php`
- Create: `src/Events/AfterEntryTranslation.php`

**Step 1: Create event classes**

```php
// BeforeEntryTranslation.php
final class BeforeEntryTranslation
{
    public function __construct(
        public readonly Entry $entry,
        public readonly string $targetSite,
        public array $units,  // mutable — listeners can modify
    ) {}
}

// AfterEntryTranslation.php
final class AfterEntryTranslation
{
    public function __construct(
        public readonly Entry $entry,
        public readonly string $targetSite,
        public array $translatedData,  // mutable — listeners can modify before save
    ) {}
}
```

**Step 2: Commit**

```bash
git commit -m "feat: before/after entry translation events"
```

---

## Task 13: TranslateEntryJob

**Files:**
- Create: `src/Jobs/TranslateEntryJob.php`
- Create: `src/Actions/TranslateEntry.php` (orchestrator action class)
- Create: `tests/Feature/TranslateEntryJobTest.php`

**Step 1: Write job tests**

```php
it('translates an entry to a target locale', function () {
    // Setup: multi-site with en + fr, entry in en
    // Mock TranslationService to return prefixed translations
    // Dispatch job
    // Assert: fr localization exists with translated content
    // Assert: last_translated_at is set
});

it('creates localization if it does not exist', function () { ... });
it('overwrites existing localization when option is set', function () { ... });
it('skips existing localization when overwrite is false', function () { ... });
it('regenerates slug when option is set', function () { ... });
it('fires before and after events', function () { ... });
it('retries on failure with backoff', function () { ... });
it('translates from non-origin source when specified', function () { ... });
```

**Step 2: Create TranslateEntry action class**

The orchestrator that the job delegates to:
1. Load source entry (origin or specified source)
2. Resolve blueprint fields
3. Extract → translate → reassemble
4. Create/update localization
5. Set metadata

**Step 3: Create TranslateEntryJob**

Thin job wrapper around TranslateEntry action. Configures queue, retries, backoff.

```php
final class TranslateEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly string $entryId,
        private readonly string $targetSite,
        private readonly ?string $sourceSite = null,
        private readonly array $options = [],
    ) {}

    public function handle(TranslateEntry $action): void
    {
        $action->handle($this->entryId, $this->targetSite, $this->sourceSite, $this->options);
    }
}
```

**Step 4: Run tests, verify pass**

**Step 5: Commit**

```bash
git commit -m "feat: translate entry job with event dispatching and retry support"
```

---

## Task 14: Service Provider — Full Wiring

**Files:**
- Modify: `src/ServiceProvider.php`
- Create: `tests/Feature/ServiceProviderTest.php`

**Step 1: Write service provider tests**

```php
it('merges config', function () {
    expect(config('content-translator.service'))->toBe('prism');
});

it('registers translation service as singleton', function () {
    expect(app(TranslationService::class))->toBeInstanceOf(TranslationService::class);
});

it('injects fieldtype into configured collection blueprints', function () {
    // Setup: create a collection in config('content-translator.collections')
    // Assert: blueprint has content_translator field
});

it('excludes blueprints in exclude_blueprints config', function () { ... });

it('does not inject into unconfigured collections', function () { ... });
```

**Step 2: Implement full ServiceProvider**

- Config registration + publish
- Translation file loading
- Service bindings (TranslationService, ContentExtractor, ContentReassembler, factory)
- `EntryBlueprintFound` listener for fieldtype injection
- Action registration
- Route registration
- Vite config with `supportsInertia()` detection
- View publishing

**Step 3: Run tests, verify pass**

**Step 4: Commit**

```bash
git commit -m "feat: service provider with config, bindings, blueprint injection, routes"
```

---

## Task 15: HTTP Controller & Routes

**Files:**
- Create: `src/Http/Controllers/TranslationController.php`
- Create: `routes/cp.php`
- Create: `tests/Feature/Http/TranslationControllerTest.php`

**Step 1: Write controller tests**

```php
// Trigger endpoint
it('dispatches translation jobs for selected locales', function () {
    Queue::fake();
    $this->loginUser();
    // POST with entry_id, target_sites, options
    $response = $this->postJson('/cp/content-translator/translate', [
        'entry_id' => $entry->id(),
        'source_site' => 'en',
        'target_sites' => ['fr', 'de'],
        'options' => ['generate_slug' => true, 'overwrite' => false],
    ]);

    $response->assertOk();
    Queue::assertPushed(TranslateEntryJob::class, 2);
    // Assert job IDs returned
});

it('requires authentication', function () { ... });
it('validates entry exists', function () { ... });
it('validates target sites exist', function () { ... });

// Status endpoint
it('returns job statuses', function () {
    // Setup: create cache entries simulating job progress
    $response = $this->getJson('/cp/content-translator/status', [
        'jobs' => ['job-id-1', 'job-id-2'],
    ]);
    $response->assertOk();
    // Assert status per job
});
```

**Step 2: Implement TranslationController**

Two endpoints:
- `trigger()` — validates request, dispatches `TranslateEntryJob` per target site, stores job tracking info in cache, returns job IDs.
- `status()` — reads job status from cache, returns per-job status (pending/running/completed/failed with error).

**Step 3: Create routes file**

```php
Route::post('content-translator/translate', [TranslationController::class, 'trigger']);
Route::get('content-translator/status', [TranslationController::class, 'status']);
```

**Step 4: Run tests, verify pass**

**Step 5: Commit**

```bash
git commit -m "feat: HTTP controller with trigger and status endpoints"
```

---

## Task 16: Bulk Action

**Files:**
- Create: `src/StatamicActions/TranslateEntryAction.php`
- Create: `tests/Feature/TranslateEntryActionTest.php`

**Step 1: Write bulk action tests**

```php
it('is visible for entries in multi-site collections', function () { ... });
it('is not visible for single-site collections', function () { ... });
it('returns callback to open translation dialog', function () { ... });
it('requires edit permission', function () { ... });
```

**Step 2: Implement TranslateEntryAction**

```php
final class TranslateEntryAction extends Action
{
    public static function title(): string
    {
        return __('content-translator::messages.translate_action');
    }

    public function run($items, $values): array
    {
        return [
            'callback' => ['openTranslationDialog', $items->map->id()->values()->all()],
        ];
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry
            && $item->collection()
            && in_array($item->collection()->handle(), config('content-translator.collections', []))
            && \Statamic\Facades\Site::all()->count() > 1;
    }

    public function authorize($user, $item): bool
    {
        return $user->can('edit', $item);
    }
}
```

**Step 3: Run tests, verify pass**

**Step 4: Commit**

```bash
git commit -m "feat: statamic bulk action for entry translation"
```

---

## Task 17: Fieldtype (PHP)

**Files:**
- Create: `src/Fieldtypes/ContentTranslatorFieldtype.php`
- Create: `tests/Unit/Fieldtypes/ContentTranslatorFieldtypeTest.php`

**Step 1: Write fieldtype tests**

```php
it('has the correct handle', function () {
    expect(ContentTranslatorFieldtype::handle())->toBe('content_translator');
});

it('provides localization status as meta data', function () { ... });
it('is not selectable in blueprint editor', function () { ... });
```

**Step 2: Implement fieldtype**

```php
final class ContentTranslatorFieldtype extends Fieldtype
{
    protected static $handle = 'content_translator';

    protected $selectable = false; // auto-injected, not manually added

    protected $categories = ['special'];

    protected function configFieldItems(): array
    {
        return [];
    }

    public function preProcess($data)
    {
        return $data;
    }

    public function process($data)
    {
        return $data;
    }

    // Provide localization state as meta for the Vue component
    public function preload(): array
    {
        // Return localization status, last_translated_at per locale, etc.
        // This powers the Vue component's initial state.
    }
}
```

**Step 3: Run tests, verify pass**

**Step 4: Commit**

```bash
git commit -m "feat: content translator fieldtype with localization meta"
```

---

## Task 18: Language Files

**Files:**
- Create: `resources/lang/en/messages.php`

**Step 1: Create English translations**

```php
return [
    'translate_action' => 'Translate',
    'translate_button' => 'Translate',
    'translate_all_missing' => 'Translate all missing',
    'translating' => 'Translating...',
    'translation_complete' => 'Translation complete',
    'translation_failed' => 'Translation failed',
    'translation_queued' => 'Translation queued',
    'retry' => 'Retry',
    'source' => 'Source',
    'overwrite_existing' => 'Overwrite existing translations',
    'generate_slugs' => 'Generate slugs from translated title',
    'outdated' => 'Outdated',
    'missing' => 'Missing',
    'translated' => 'Translated',
    'cancel' => 'Cancel',
    'translate_selected' => 'Translate selected',
];
```

**Step 2: Commit**

```bash
git commit -m "feat: english language file"
```

---

## Task 19: Frontend — Core JS Layer

**Files:**
- Create: `resources/js/core/api.ts`
- Create: `resources/js/core/polling.ts`
- Create: `resources/js/core/injection.ts`
- Create: `resources/js/core/types.ts`

**Step 1: Create TypeScript types**

```typescript
// types.ts
export interface LocaleStatus {
    handle: string;
    name: string;
    exists: boolean;
    lastTranslatedAt: string | null;
    isOutdated: boolean;
    status: 'missing' | 'translated' | 'outdated' | 'manual';
}

export interface TranslationJob {
    id: string;
    targetSite: string;
    status: 'pending' | 'running' | 'completed' | 'failed';
    error?: string;
}

export interface TranslationRequest {
    entryId: string | string[];
    sourceSite: string;
    targetSites: string[];
    options: {
        generateSlug: boolean;
        overwrite: boolean;
    };
}
```

**Step 2: Implement API client**

```typescript
// api.ts
export async function triggerTranslation(request: TranslationRequest): Promise<{ jobIds: string[] }> { ... }
export async function checkStatus(jobIds: string[]): Promise<TranslationJob[]> { ... }
```

**Step 3: Implement polling with backoff**

```typescript
// polling.ts
export function pollJobs(jobIds: string[], onUpdate: (jobs: TranslationJob[]) => void, options?: { interval?: number, maxAttempts?: number }): () => void { ... }
```

**Step 4: Implement badge injection helper**

```typescript
// injection.ts
export function injectBadges(localeStatuses: LocaleStatus[], version: 'v5' | 'v6'): boolean { ... }
export function removeBadges(): void { ... }
```

**Step 5: Commit**

```bash
git commit -m "feat: frontend core layer — api client, polling, badge injection"
```

---

## Task 20: Frontend — Vue Components & Entry Points

**Files:**
- Create: `resources/js/v5/addon.ts`
- Create: `resources/js/v5/components/TranslationDialog.vue`
- Create: `resources/js/v5/components/TranslatorFieldtype.vue`
- Create: `resources/js/v6/addon.ts`
- Create: `resources/js/v6/components/TranslationDialog.vue`
- Create: `resources/js/v6/components/TranslatorFieldtype.vue`

**Step 1: Implement the TranslationDialog component**

Shared logic, version-specific template/reactivity:
- Source locale dropdown (defaults to origin)
- Locale rows with checkboxes, status indicators
- Options (generate slug, overwrite existing)
- Translate button dispatches via API
- Polling updates per-row status
- Error display with retry per row

**Step 2: Implement the TranslatorFieldtype component**

- Reads `meta` from the fieldtype preload (localization statuses)
- Renders "Translate" button
- On mount: attempts badge injection into Sites panel
- localStorage cache for injection mode
- MutationObserver for re-injection
- Opens TranslationDialog on click

**Step 3: Implement v5 entry point**

```typescript
// v5/addon.ts
import TranslatorFieldtype from './components/TranslatorFieldtype.vue';
import TranslationDialog from './components/TranslationDialog.vue';

Statamic.booting(() => {
    Statamic.$components.register('content_translator-fieldtype', TranslatorFieldtype);
    Statamic.$components.register('translation-dialog', TranslationDialog);

    Statamic.$callbacks.add('openTranslationDialog', (entryIds: string[]) => {
        // Open dialog in bulk mode
    });
});
```

**Step 4: Implement v6 entry point**

Same component registration, but components use Vue 3 composition API and `publishContextKey` for state access.

**Step 5: Build and verify**

```bash
npm install
npm run build
```

**Step 6: Commit**

```bash
git commit -m "feat: vue components — translation dialog, fieldtype, badge injection"
```

---

## Task 21: Integration Testing in Sandboxes

**Files:**
- No new files — manual testing in sandbox environments

**Step 1: Install addon in v5 sandbox**

```bash
cd ../statamic-content-translator-test
composer require el-schneider/statamic-content-translator --dev
```

Configure multi-site, add collections to config, create test entries.

**Step 2: Verify in v5**

- Fieldtype auto-injected into sidebar ✓
- Badge injection into Sites panel ✓
- Dialog opens from sidebar button ✓
- Translation dispatches and polls correctly ✓
- Bulk action visible and works ✓

**Step 3: Repeat in v6 sandbox**

Same verification in v6 environment.

**Step 4: Run full test suite**

```bash
./vendor/bin/pest
./vendor/bin/pint --test
```

**Step 5: Commit any fixes**

```bash
git commit -m "fix: integration testing adjustments"
```

---

## Task Dependency Graph

```
Task 1 (scaffold)
├── Task 2 (TranslationUnit)
│   ├── Task 3 (extractor tier 1)
│   │   └── Task 4 (extractor tier 2)
│   │       └── Task 7 (extractor tier 3 + bard integration)
│   ├── Task 5 (bard serializer)
│   │   └── Task 6 (bard parser)
│   │       └── Task 8 (reassembler)
│   └── Task 9 (service contract + factory)
│       ├── Task 10 (prism service)
│       └── Task 11 (deepl service)
├── Task 12 (events)
├── Task 13 (job) ← depends on 7, 8, 9, 12
├── Task 14 (service provider) ← depends on 9, 13
├── Task 15 (controller) ← depends on 13
├── Task 16 (bulk action)
├── Task 17 (fieldtype PHP)
├── Task 18 (language files)
├── Task 19 (frontend core) ← parallel with backend tasks
└── Task 20 (vue components) ← depends on 19
    └── Task 21 (integration testing) ← depends on everything
```

Tasks 3–8 can be parallelized (extraction and reassembly are independent). Tasks 10–11 are independent. Tasks 14–18 are mostly independent after 13.

---

## Final Acceptance Criteria

These user stories must all pass in both v5 and v6 sandboxes before the addon is considered complete.

**US-1: Single Entry Translation**
As an editor, I can open an entry in the CP, click "Translate" in the sidebar, select target locales, and have the entry translated asynchronously. I see per-locale status indicators (spinner → ✓ → ⚠️) in the dialog while translations process.

**US-2: Bulk Translation**
As an editor, I can select multiple entries in a collection listing, run the "Translate" bulk action, choose target locales, and have all entries translated. I see per-locale progress counters in the dialog.

**US-3: Overwrite Protection**
As an editor, when I open the translation dialog, locales with existing translations are unchecked by default. I must explicitly enable "Overwrite existing translations" to re-translate them.

**US-4: Translation Staleness**
As an editor, I can see in the Sites panel which localizations are up-to-date, outdated, or missing, via injected badges showing timestamps or warnings.

**US-5: Source Selection**
As an editor, the translation dialog defaults to the origin entry as source, but I can select a different source locale from a dropdown.

**US-6: Slug Generation**
As an editor, I can choose to auto-generate slugs from the translated title via a checkbox in the dialog.

**US-7: Complex Content Preservation**
As a developer, when an entry with Bard fields (including custom marks/extensions, interlaced sets, nested replicators) is translated, all structural data is preserved — only text content is translated.

**US-8: DeepL Backend**
As a developer, I can configure DeepL as the translation service with per-language formality settings, and translations maintain full entry context.

**US-9: Prism/LLM Backend**
As a developer, I can configure any Prism-supported LLM provider, customize prompts via publishable Blade views, and set per-language prompt overrides.

**US-10: Zero-Config Blueprint Setup**
As a developer, I add collection handles to `content-translator.collections` config and the fieldtype auto-injects into all blueprints. I can exclude specific blueprints via `exclude_blueprints` dot notation.

**US-11: Retry on Failure**
As an editor, when a translation fails (rate limit, API error), I see the error inline in the dialog with a Retry button. The job also retries automatically with backoff.

**US-12: v5/v6 Compatibility**
As a developer, the addon works on both Statamic v5 and v6 without configuration changes.
