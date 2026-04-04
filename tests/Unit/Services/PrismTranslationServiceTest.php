<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use ElSchneider\ContentTranslator\Services\PrismTranslationService;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

uses(Tests\TestCase::class);

beforeEach(function () {
    config([
        'statamic.content-translator.prism.provider' => 'anthropic',
        'statamic.content-translator.prism.model' => 'claude-sonnet-4-20250514',
        'statamic.content-translator.max_units_per_request' => null,
    ]);
});

function makeStructuredResponse(array $structured): StructuredResponse
{
    return new StructuredResponse(
        steps: collect([]),
        text: json_encode($structured),
        structured: $structured,
        finishReason: FinishReason::Stop,
        usage: new Usage(100, 50),
        meta: new Meta('req-1', 'claude-sonnet-4-20250514'),
    );
}

it('returns empty array when no units provided', function () {
    $service = app(PrismTranslationService::class);

    $result = $service->translate([], 'en', 'fr');

    expect($result)->toBe([]);
});

it('sends all units in a single request', function () {
    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Bonjour'],
            ['id' => 'meta', 'text' => 'La description'],
        ]),
    ]);

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
        new TranslationUnit('meta', 'Description', TranslationFormat::Plain),
    ];

    $service = app(PrismTranslationService::class);
    $result = $service->translate($units, 'en', 'fr');

    $fake->assertCallCount(1);

    expect($result)->toHaveCount(2);
    expect($result[0]->translatedText)->toBe('Bonjour');
    expect($result[1]->translatedText)->toBe('La description');
});

it('maps translated text back to correct units by id', function () {
    // Return in different order to verify id-based mapping
    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'body', 'text' => 'Le corps'],
            ['id' => 'title', 'text' => 'Le titre'],
        ]),
    ]);

    $units = [
        new TranslationUnit('title', 'The title', TranslationFormat::Plain),
        new TranslationUnit('body', 'The body', TranslationFormat::Plain),
    ];

    $service = app(PrismTranslationService::class);
    $result = $service->translate($units, 'en', 'fr');

    expect($result)->toHaveCount(2);

    $byPath = collect($result)->keyBy(fn ($u) => $u->path);
    expect($byPath->get('title')->translatedText)->toBe('Le titre');
    expect($byPath->get('body')->translatedText)->toBe('Le corps');
});

it('preserves original unit properties when setting translated text', function () {
    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'content', 'text' => '<b>Bonjour</b>'],
        ]),
    ]);

    $markMap = [0 => ['type' => 'bold']];
    $units = [
        new TranslationUnit('content', '<b>Hello</b>', TranslationFormat::Html, null, $markMap),
    ];

    $service = app(PrismTranslationService::class);
    $result = $service->translate($units, 'en', 'fr');

    expect($result[0]->path)->toBe('content');
    expect($result[0]->format)->toBe(TranslationFormat::Html);
    expect($result[0]->markMap)->toBe($markMap);
    expect($result[0]->translatedText)->toBe('<b>Bonjour</b>');
});

it('uses configured provider and model', function () {
    config([
        'statamic.content-translator.prism.provider' => 'openai',
        'statamic.content-translator.prism.model' => 'gpt-4o',
    ]);

    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Bonjour'],
        ]),
    ]);

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    $service = app(PrismTranslationService::class);
    $service->translate($units, 'en', 'fr');

    $fake->assertRequest(function (array $requests) {
        expect($requests[0]->model())->toBe('gpt-4o');
        expect($requests[0]->provider())->toBe('openai');
    });
});

it('uses openai-compatible object schema with translations key', function () {
    config([
        'statamic.content-translator.prism.provider' => 'openai',
        'statamic.content-translator.prism.model' => 'gpt-4o',
    ]);

    $fake = Prism::fake([
        makeStructuredResponse([
            'translations' => [
                ['id' => 'title', 'text' => 'Bonjour'],
            ],
        ]),
    ]);

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    $service = app(PrismTranslationService::class);
    $result = $service->translate($units, 'en', 'fr');

    expect($result[0]->translatedText)->toBe('Bonjour');

    $fake->assertRequest(function (array $requests) {
        $schema = $requests[0]->schema()->toArray();
        expect($schema['type'])->toBe('object');
        expect($schema['properties'])->toHaveKey('translations');
        expect($requests[0]->prompt())->toContain('"translations"');
    });
});

it('includes unit ids and texts in user prompt as json', function () {
    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Bonjour'],
        ]),
    ]);

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    $service = app(PrismTranslationService::class);
    $service->translate($units, 'en', 'fr');

    $fake->assertRequest(function (array $requests) {
        $prompt = $requests[0]->prompt();

        expect($prompt)->toContain('"id"');
        expect($prompt)->toContain('"text"');
        expect($prompt)->toContain('title');
        expect($prompt)->toContain('Hello');
    });
});

it('includes system prompt', function () {
    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Bonjour'],
        ]),
    ]);

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    $service = app(PrismTranslationService::class);
    $service->translate($units, 'en', 'fr');

    $fake->assertRequest(function (array $requests) {
        expect($requests[0]->systemPrompts())->not->toBeEmpty();
        expect($requests[0]->systemPrompts()[0]->content)->not->toBeEmpty();
    });
});

it('uses structured output schema with array of id/text objects', function () {
    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Bonjour'],
        ]),
    ]);

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    $service = app(PrismTranslationService::class);
    $service->translate($units, 'en', 'fr');

    $fake->assertRequest(function (array $requests) {
        $schema = $requests[0]->schema();
        $schemaArray = $schema->toArray();

        // Schema should be an array type
        expect($schemaArray['type'])->toBe('array');

        // Items should have id and text properties
        $itemSchema = $schemaArray['items'];
        expect($itemSchema['properties'])->toHaveKey('id');
        expect($itemSchema['properties'])->toHaveKey('text');
    });
});

it('chunks requests when max_units_per_request is set', function () {
    config(['statamic.content-translator.max_units_per_request' => 2]);

    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Titre'],
            ['id' => 'body', 'text' => 'Corps'],
        ]),
        makeStructuredResponse([
            ['id' => 'meta', 'text' => 'Méta'],
            ['id' => 'summary', 'text' => 'Résumé'],
        ]),
    ]);

    $units = [
        new TranslationUnit('title', 'Title', TranslationFormat::Plain),
        new TranslationUnit('body', 'Body', TranslationFormat::Plain),
        new TranslationUnit('meta', 'Meta', TranslationFormat::Plain),
        new TranslationUnit('summary', 'Summary', TranslationFormat::Plain),
    ];

    $service = app(PrismTranslationService::class);
    $result = $service->translate($units, 'en', 'fr');

    $fake->assertCallCount(2);
    expect($result)->toHaveCount(4);
});

it('handles single unit without chunking', function () {
    config(['statamic.content-translator.max_units_per_request' => 10]);

    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Bonjour'],
        ]),
    ]);

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    $service = app(PrismTranslationService::class);
    $result = $service->translate($units, 'en', 'fr');

    $fake->assertCallCount(1);
    expect($result)->toHaveCount(1);
    expect($result[0]->translatedText)->toBe('Bonjour');
});

it('does not chunk when max_units_per_request is null', function () {
    config(['statamic.content-translator.max_units_per_request' => null]);

    $fake = Prism::fake([
        makeStructuredResponse([
            ['id' => 'a', 'text' => 'A'],
            ['id' => 'b', 'text' => 'B'],
            ['id' => 'c', 'text' => 'C'],
        ]),
    ]);

    $units = [
        new TranslationUnit('a', 'A', TranslationFormat::Plain),
        new TranslationUnit('b', 'B', TranslationFormat::Plain),
        new TranslationUnit('c', 'C', TranslationFormat::Plain),
    ];

    $service = app(PrismTranslationService::class);
    $result = $service->translate($units, 'en', 'fr');

    $fake->assertCallCount(1);
    expect($result)->toHaveCount(3);
});

it('throws when prism response misses a unit translation', function () {
    Prism::fake([
        makeStructuredResponse([
            ['id' => 'title', 'text' => 'Bonjour'],
        ]),
    ]);

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
        new TranslationUnit('body', 'Body', TranslationFormat::Plain),
    ];

    $service = app(PrismTranslationService::class);

    $service->translate($units, 'en', 'fr');
})->throws(RuntimeException::class, 'Missing translation for unit id [body].');

it('throws for invalid max_units_per_request config', function () {
    config(['statamic.content-translator.max_units_per_request' => 0]);

    $service = app(PrismTranslationService::class);

    $service->translate([
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
    ], 'en', 'fr');
})->throws(InvalidArgumentException::class);
