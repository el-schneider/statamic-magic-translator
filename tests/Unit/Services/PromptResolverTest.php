<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use ElSchneider\ContentTranslator\Services\PromptResolver;

uses(Tests\TestCase::class);

it('resolves default system prompt view name', function () {
    config(['content-translator.prism.prompts.system' => 'content-translator::prompts.system']);

    $resolver = app(PromptResolver::class);

    expect($resolver->resolveViewName('system', 'fr'))->toBe('content-translator::prompts.system');
});

it('resolves language-specific override when it exists', function () {
    config([
        'content-translator.prism.prompts.system' => 'content-translator::prompts.system',
        'content-translator.prism.prompts.overrides' => [
            'ja' => ['system' => 'content-translator::prompts.system-ja'],
        ],
    ]);

    $resolver = app(PromptResolver::class);

    expect($resolver->resolveViewName('system', 'ja'))->toBe('content-translator::prompts.system-ja');
});

it('falls back to default when no override exists for locale', function () {
    config([
        'content-translator.prism.prompts.system' => 'content-translator::prompts.system',
        'content-translator.prism.prompts.overrides' => [
            'ja' => ['system' => 'content-translator::prompts.system-ja'],
        ],
    ]);

    $resolver = app(PromptResolver::class);

    // 'de' has no override, should fall back to default
    expect($resolver->resolveViewName('system', 'de'))->toBe('content-translator::prompts.system');
});

it('falls back to default when override exists for locale but not for prompt type', function () {
    config([
        'content-translator.prism.prompts.system' => 'content-translator::prompts.system',
        'content-translator.prism.prompts.user' => 'content-translator::prompts.user',
        'content-translator.prism.prompts.overrides' => [
            'ja' => ['system' => 'content-translator::prompts.system-ja'],
        ],
    ]);

    $resolver = app(PromptResolver::class);

    // 'ja' has override for 'system' but not for 'user'
    expect($resolver->resolveViewName('user', 'ja'))->toBe('content-translator::prompts.user');
});

it('renders system view with locale name variables', function () {
    $resolver = app(PromptResolver::class);

    $result = $resolver->resolve('system', 'en', 'fr', []);

    // The system view uses $sourceLocaleName and $targetLocaleName
    expect($result)->toContain('English');
    expect($result)->toContain('French');
});

it('renders user view with locale name variables', function () {
    $resolver = app(PromptResolver::class);

    $result = $resolver->resolve('user', 'en', 'fr', []);

    expect($result)->toContain('English');
    expect($result)->toContain('French');
});

it('passes hasHtmlUnits flag to format-rules when html units present', function () {
    $resolver = app(PromptResolver::class);

    $units = [
        new TranslationUnit('content', '<b>Hello</b>', TranslationFormat::Html),
    ];

    $result = $resolver->resolve('system', 'en', 'fr', $units);

    expect($result)->toContain('HTML');
});

it('passes hasMarkdownUnits flag to format-rules when markdown units present', function () {
    $resolver = app(PromptResolver::class);

    $units = [
        new TranslationUnit('body', '**Hello**', TranslationFormat::Markdown),
    ];

    $result = $resolver->resolve('system', 'en', 'fr', $units);

    expect($result)->toContain('Markdown');
});

it('does not include html rules when no html units present', function () {
    $resolver = app(PromptResolver::class);

    $units = [
        new TranslationUnit('title', 'Hello', TranslationFormat::Plain),
    ];

    $result = $resolver->resolve('system', 'en', 'fr', $units);

    // When no HTML units, the html format rules should not appear
    expect($result)->not->toContain('HTML tags');
});
