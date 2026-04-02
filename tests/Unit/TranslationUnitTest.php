<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Data\TranslationUnit;

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
    $unit = new TranslationUnit(
        path: 'content.0.content',
        text: '<b>Hello</b>',
        format: TranslationFormat::Html,
        markMap: $markMap,
    );

    expect($unit->format)->toBe(TranslationFormat::Html);
    expect($unit->markMap)->toBe($markMap);
});

it('creates a markdown unit', function () {
    $unit = new TranslationUnit(path: 'body', text: '**Hello**', format: TranslationFormat::Markdown);

    expect($unit->format)->toBe(TranslationFormat::Markdown);
    expect($unit->text)->toBe('**Hello**');
});

it('can set translated text immutably', function () {
    $unit = new TranslationUnit(path: 'title', text: 'Hello', format: TranslationFormat::Plain);
    $translated = $unit->withTranslation('Bonjour');

    expect($translated->translatedText)->toBe('Bonjour');
    expect($translated->text)->toBe('Hello');      // original text unchanged
    expect($translated->path)->toBe('title');       // other props preserved
    expect($translated->format)->toBe(TranslationFormat::Plain);
    expect($unit->translatedText)->toBeNull();      // original instance immutable
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

it('TranslationFormat enum has correct backed values', function () {
    expect(TranslationFormat::Plain->value)->toBe('plain');
    expect(TranslationFormat::Html->value)->toBe('html');
    expect(TranslationFormat::Markdown->value)->toBe('markdown');
});
