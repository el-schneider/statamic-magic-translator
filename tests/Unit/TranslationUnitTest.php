<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Data\TranslationFormat;
use ElSchneider\MagicTranslator\Data\TranslationUnit;

it('can set translated text immutably', function () {
    $unit = new TranslationUnit(path: 'title', text: 'Hello', format: TranslationFormat::Plain);
    $translated = $unit->withTranslation('Bonjour');

    expect($translated->translatedText)->toBe('Bonjour');
    expect($translated->text)->toBe('Hello');
    expect($translated->path)->toBe('title');
    expect($translated->format)->toBe(TranslationFormat::Plain);
    expect($unit->translatedText)->toBeNull();
});

it('withTranslation preserves the mark map', function () {
    $markMap = [0 => ['type' => 'bold'], 1 => ['type' => 'link', 'attrs' => ['href' => 'https://example.com']]];
    $unit = new TranslationUnit(
        path: 'content.body',
        text: '<b>Hello</b> <a href="https://example.com">click</a>',
        format: TranslationFormat::Html,
        markMap: $markMap,
    );

    $translated = $unit->withTranslation('<b>Bonjour</b> <a href="https://example.com">cliquer</a>');

    expect($translated->markMap)->toBe($markMap);
    expect($translated->translatedText)->toBe('<b>Bonjour</b> <a href="https://example.com">cliquer</a>');
});
