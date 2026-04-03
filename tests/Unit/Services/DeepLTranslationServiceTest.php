<?php

declare(strict_types=1);

use DeepL\TextResult;
use DeepL\TranslateTextOptions;
use DeepL\Translator;
use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use ElSchneider\ContentTranslator\Services\DeepLTranslationService;

uses(Tests\TestCase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Build a TextResult the DeepL SDK would return.
 */
function deeplTextResult(string $text): TextResult
{
    return new TextResult($text, 'en', mb_strlen($text));
}

/**
 * Create a DeepLTranslationService with the given mock/spy translator.
 */
function deeplService(Translator $translator): DeepLTranslationService
{
    return new DeepLTranslationService($translator);
}

/**
 * Create a Mockery mock of the Translator that returns the given translated XML string.
 */
function mockTranslator(string $translatedXml): Translator
{
    $mock = Mockery::mock(Translator::class);
    $mock->shouldReceive('translateText')
        ->once()
        ->andReturn(deeplTextResult($translatedXml));

    return $mock;
}

// ─── Empty input ──────────────────────────────────────────────────────────────

it('returns empty array when no units are provided', function () {
    $translator = Mockery::mock(Translator::class);
    $translator->shouldNotReceive('translateText');

    $service = deeplService($translator);
    $result = $service->translate([], 'en', 'de');

    expect($result)->toBe([]);
});

// ─── Concatenation ────────────────────────────────────────────────────────────

it('concatenates units with ct-unit id delimiters', function () {
    $capturedText = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text) use (&$capturedText) {
            $capturedText = $text;

            return deeplTextResult('<ct-unit id="0">Hallo</ct-unit><ct-unit id="1">Welt</ct-unit>');
        });

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
        new TranslationUnit('body', 'World', TranslationFormat::Plain),
    ];

    deeplService($translator)->translate($units, 'en', 'de');

    expect($capturedText)->toBe(
        '<ct-unit id="0">Hello</ct-unit><ct-unit id="1">World</ct-unit>'
    );
});

it('assigns sequential ids starting from 0 for each chunk', function () {
    $capturedText = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text) use (&$capturedText) {
            $capturedText = $text;

            return deeplTextResult('<ct-unit id="0">Eins</ct-unit><ct-unit id="1">Zwei</ct-unit><ct-unit id="2">Drei</ct-unit>');
        });

    $units = [
        new TranslationUnit('a', 'One', TranslationFormat::Plain),
        new TranslationUnit('b', 'Two', TranslationFormat::Plain),
        new TranslationUnit('c', 'Three', TranslationFormat::Plain),
    ];

    deeplService($translator)->translate($units, 'en', 'de');

    expect($capturedText)->toBe(
        '<ct-unit id="0">One</ct-unit><ct-unit id="1">Two</ct-unit><ct-unit id="2">Three</ct-unit>'
    );
});

// ─── Response splitting ───────────────────────────────────────────────────────

it('splits response back by ct-unit tags and maps to units', function () {
    $translator = mockTranslator(
        '<ct-unit id="0">Bonjour</ct-unit><ct-unit id="1">Le monde</ct-unit>'
    );

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
        new TranslationUnit('body', 'World', TranslationFormat::Plain),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'fr');

    expect($result)->toHaveCount(2);
    expect($result[0]->path)->toBe('title');
    expect($result[0]->translatedText)->toBe('Bonjour');
    expect($result[1]->path)->toBe('body');
    expect($result[1]->translatedText)->toBe('Le monde');
});

it('preserves original unit properties when setting translated text', function () {
    $markMap = [0 => ['type' => 'bold']];

    $translator = mockTranslator('<ct-unit id="0"><b>Bonjour</b></ct-unit>');

    $units = [
        new TranslationUnit('content', '<b>Hello</b>', TranslationFormat::Html, null, $markMap),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'fr');

    expect($result[0]->path)->toBe('content');
    expect($result[0]->format)->toBe(TranslationFormat::Html);
    expect($result[0]->markMap)->toBe($markMap);
    expect($result[0]->text)->toBe('<b>Hello</b>');
    expect($result[0]->translatedText)->toBe('<b>Bonjour</b>');
});

it('handles multi-line translated content within ct-unit tags', function () {
    $multiLine = "<ct-unit id=\"0\">Ligne un\nLigne deux</ct-unit>";

    $translator = mockTranslator($multiLine);

    $units = [
        new TranslationUnit('body', "Line one\nLine two", TranslationFormat::Plain),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'fr');

    expect($result[0]->translatedText)->toBe("Ligne un\nLigne deux");
});

it('maps units by ct-unit id even when DeepL reorders tags', function () {
    $translator = mockTranslator(
        '<ct-unit id="1">Le monde</ct-unit><ct-unit id="0">Bonjour</ct-unit>'
    );

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
        new TranslationUnit('body', 'World', TranslationFormat::Plain),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'fr');

    expect($result[0]->translatedText)->toBe('Bonjour');
    expect($result[1]->translatedText)->toBe('Le monde');
});

it('parses ct-unit tags with flexible attribute order and whitespace', function () {
    $translator = mockTranslator(
        "<ct-unit data-kind=\"x\" id='0' >Bonjour</ct-unit >\n<ct-unit id=\"1\" class=\"y\">Le monde</ct-unit>"
    );

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
        new TranslationUnit('body', 'World', TranslationFormat::Plain),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'fr');

    expect($result[0]->translatedText)->toBe('Bonjour');
    expect($result[1]->translatedText)->toBe('Le monde');
});

it('throws when DeepL response strips ct-unit tags', function () {
    $translator = mockTranslator('Bonjour Le monde');

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
        new TranslationUnit('body', 'World', TranslationFormat::Plain),
    ];

    expect(fn () => deeplService($translator)->translate($units, 'en', 'fr'))
        ->toThrow(\RuntimeException::class, 'Missing translation for unit index [0]');
});

// ─── XML tag handling ─────────────────────────────────────────────────────────

it('passes tag_handling xml option to the translator', function () {
    $capturedOptions = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return deeplTextResult('<ct-unit id="0">Bonjour</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    deeplService($translator)->translate($units, 'en', 'fr');

    expect($capturedOptions)->toHaveKey(TranslateTextOptions::TAG_HANDLING);
    expect($capturedOptions[TranslateTextOptions::TAG_HANDLING])->toBe('xml');
});

// ─── Formality ────────────────────────────────────────────────────────────────

it('passes formality from config to the translator', function () {
    config(['content-translator.deepl.formality' => 'more']);

    $capturedOptions = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return deeplTextResult('<ct-unit id="0">Hallo</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    deeplService($translator)->translate($units, 'en', 'de');

    expect($capturedOptions)->toHaveKey(TranslateTextOptions::FORMALITY);
    expect($capturedOptions[TranslateTextOptions::FORMALITY])->toBe('more');
});

it('uses default formality when config is set to default', function () {
    config(['content-translator.deepl.formality' => 'default']);

    $capturedOptions = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return deeplTextResult('<ct-unit id="0">Hallo</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    deeplService($translator)->translate($units, 'en', 'de');

    expect($capturedOptions[TranslateTextOptions::FORMALITY])->toBe('default');
});

// ─── Per-language formality overrides ────────────────────────────────────────

it('applies per-language formality override for the target locale', function () {
    config([
        'content-translator.deepl.formality' => 'default',
        'content-translator.deepl.overrides' => [
            'de' => ['formality' => 'prefer_more'],
        ],
    ]);

    $capturedOptions = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return deeplTextResult('<ct-unit id="0">Hallo</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    deeplService($translator)->translate($units, 'en', 'de');

    expect($capturedOptions[TranslateTextOptions::FORMALITY])->toBe('prefer_more');
});

it('uses global formality when no per-language override is set', function () {
    config([
        'content-translator.deepl.formality' => 'less',
        'content-translator.deepl.overrides' => [
            'de' => ['formality' => 'more'],
        ],
    ]);

    $capturedOptions = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return deeplTextResult('<ct-unit id="0">Bonjour</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    // Target is 'fr', override is for 'de' — should use global 'less'
    deeplService($translator)->translate($units, 'en', 'fr');

    expect($capturedOptions[TranslateTextOptions::FORMALITY])->toBe('less');
});

it('matches override by base language code ignoring regional variant', function () {
    config([
        'content-translator.deepl.formality' => 'default',
        'content-translator.deepl.overrides' => [
            'de' => ['formality' => 'more'],
        ],
    ]);

    $capturedOptions = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return deeplTextResult('<ct-unit id="0">Hallo</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    // Target is 'de-AT' — base is 'de', override should match
    deeplService($translator)->translate($units, 'en', 'de-AT');

    expect($capturedOptions[TranslateTextOptions::FORMALITY])->toBe('more');
});

// ─── Locale code mapping ──────────────────────────────────────────────────────

it('maps source locale to uppercase base code', function () {
    $capturedSource = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target) use (&$capturedSource) {
            $capturedSource = $source;

            return deeplTextResult('<ct-unit id="0">Hallo</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    deeplService($translator)->translate($units, 'en', 'de');

    expect($capturedSource)->toBe('EN');
});

it('strips regional variant from source locale', function () {
    $capturedSource = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target) use (&$capturedSource) {
            $capturedSource = $source;

            return deeplTextResult('<ct-unit id="0">Hallo</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    // en-GB as source → should become 'EN' (base code only)
    deeplService($translator)->translate($units, 'en-GB', 'de');

    expect($capturedSource)->toBe('EN');
});

dataset('target locale mappings', [
    ['en', 'EN-US'],
    ['en-us', 'EN-US'],
    ['en-US', 'EN-US'],
    ['en-gb', 'EN-GB'],
    ['en-GB', 'EN-GB'],
    ['de', 'DE'],
    ['fr', 'FR'],
    ['es', 'ES'],
    ['it', 'IT'],
    ['nl', 'NL'],
    ['pl', 'PL'],
    ['ru', 'RU'],
    ['ja', 'JA'],
    ['ko', 'KO'],
    ['pt', 'PT-PT'],
    ['pt-pt', 'PT-PT'],
    ['pt-PT', 'PT-PT'],
    ['pt-br', 'PT-BR'],
    ['pt-BR', 'PT-BR'],
    ['zh', 'ZH-HANS'],
    ['zh-cn', 'ZH-HANS'],
    ['zh-hans', 'ZH-HANS'],
    ['zh-tw', 'ZH-HANT'],
    ['zh-hant', 'ZH-HANT'],
]);

it('maps target locale code correctly', function (string $statamicLocale, string $expectedDeepLLocale) {
    $capturedTarget = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target) use (&$capturedTarget) {
            $capturedTarget = $target;

            // Build a valid response for each unit
            return deeplTextResult('<ct-unit id="0">translated</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    deeplService($translator)->translate($units, 'en', $statamicLocale);

    expect($capturedTarget)->toBe($expectedDeepLLocale);
})->with('target locale mappings');

it('handles statamic underscore locale format (en_US)', function () {
    $capturedTarget = null;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturnUsing(function (string $text, ?string $source, string $target) use (&$capturedTarget) {
            $capturedTarget = $target;

            return deeplTextResult('<ct-unit id="0">Bonjour</ct-unit>');
        });

    $units = [new TranslationUnit('title', 'Hello', TranslationFormat::Plain)];

    // Statamic sometimes uses pt_BR format with underscores
    deeplService($translator)->translate($units, 'en', 'pt_BR');

    expect($capturedTarget)->toBe('PT-BR');
});

// ─── Chunking ─────────────────────────────────────────────────────────────────

it('sends one request when max_units_per_request is null', function () {
    config(['content-translator.max_units_per_request' => null]);

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->once()
        ->andReturn(deeplTextResult(
            '<ct-unit id="0">A</ct-unit><ct-unit id="1">B</ct-unit><ct-unit id="2">C</ct-unit>'
        ));

    $units = [
        new TranslationUnit('a', 'Alpha', TranslationFormat::Plain),
        new TranslationUnit('b', 'Beta', TranslationFormat::Plain),
        new TranslationUnit('c', 'Gamma', TranslationFormat::Plain),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'de');

    expect($result)->toHaveCount(3);
    expect($result[0]->translatedText)->toBe('A');
    expect($result[1]->translatedText)->toBe('B');
    expect($result[2]->translatedText)->toBe('C');
});

it('chunks requests when max_units_per_request is set', function () {
    config(['content-translator.max_units_per_request' => 2]);

    $callCount = 0;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->times(2)
        ->andReturnUsing(function (string $text) use (&$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return deeplTextResult('<ct-unit id="0">Eins</ct-unit><ct-unit id="1">Zwei</ct-unit>');
            }

            return deeplTextResult('<ct-unit id="0">Drei</ct-unit><ct-unit id="1">Vier</ct-unit>');
        });

    $units = [
        new TranslationUnit('a', 'One', TranslationFormat::Plain),
        new TranslationUnit('b', 'Two', TranslationFormat::Plain),
        new TranslationUnit('c', 'Three', TranslationFormat::Plain),
        new TranslationUnit('d', 'Four', TranslationFormat::Plain),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'de');

    expect($result)->toHaveCount(4);
    expect($result[0]->translatedText)->toBe('Eins');
    expect($result[1]->translatedText)->toBe('Zwei');
    expect($result[2]->translatedText)->toBe('Drei');
    expect($result[3]->translatedText)->toBe('Vier');
});

it('chunks correctly when unit count is not evenly divisible by chunk size', function () {
    config(['content-translator.max_units_per_request' => 2]);

    $callCount = 0;

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->times(2)
        ->andReturnUsing(function (string $text) use (&$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return deeplTextResult('<ct-unit id="0">Eins</ct-unit><ct-unit id="1">Zwei</ct-unit>');
            }

            return deeplTextResult('<ct-unit id="0">Drei</ct-unit>');
        });

    $units = [
        new TranslationUnit('a', 'One', TranslationFormat::Plain),
        new TranslationUnit('b', 'Two', TranslationFormat::Plain),
        new TranslationUnit('c', 'Three', TranslationFormat::Plain),
    ];

    $result = deeplService($translator)->translate($units, 'en', 'de');

    expect($result)->toHaveCount(3);
    expect($result[2]->translatedText)->toBe('Drei');
});

it('ids restart from 0 in each chunk', function () {
    config(['content-translator.max_units_per_request' => 2]);

    $capturedTexts = [];

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('translateText')
        ->times(2)
        ->andReturnUsing(function (string $text) use (&$capturedTexts) {
            $capturedTexts[] = $text;

            if (count($capturedTexts) === 1) {
                return deeplTextResult('<ct-unit id="0">Eins</ct-unit><ct-unit id="1">Zwei</ct-unit>');
            }

            return deeplTextResult('<ct-unit id="0">Drei</ct-unit>');
        });

    $units = [
        new TranslationUnit('a', 'One', TranslationFormat::Plain),
        new TranslationUnit('b', 'Two', TranslationFormat::Plain),
        new TranslationUnit('c', 'Three', TranslationFormat::Plain),
    ];

    deeplService($translator)->translate($units, 'en', 'de');

    // First chunk: ids 0,1
    expect($capturedTexts[0])->toContain('id="0"');
    expect($capturedTexts[0])->toContain('id="1"');
    // Second chunk: ids restart from 0
    expect($capturedTexts[1])->toContain('id="0"');
    expect($capturedTexts[1])->not->toContain('id="2"');
});
