<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Extraction\FieldClassifier;
use ElSchneider\MagicTranslator\Extraction\FieldTier;

dataset('tier1 types', ['text', 'textarea', 'markdown', 'link']);
dataset('tier2 types', ['replicator', 'grid', 'table']);
dataset('tier3 types', ['bard']);
dataset('skip types', [
    'assets',
    'toggle',
    'integer',
    'float',
    'date',
    'color',
    'code',
    'select',
    'radio',
    'checkboxes',
    'entries',
    'terms',
    'users',
    'video',
    'yaml',
    'template',
    'section',
    'slug',
    'some_custom_unknown_type',
]);

it('classifies tier1 types', function (string $type) {
    expect(FieldClassifier::classify(['type' => $type, 'localizable' => true]))->toBe(FieldTier::Tier1);
})->with('tier1 types');

it('classifies tier2 types', function (string $type) {
    expect(FieldClassifier::classify(['type' => $type, 'localizable' => true]))->toBe(FieldTier::Tier2);
})->with('tier2 types');

it('classifies tier3 types', function (string $type) {
    expect(FieldClassifier::classify(['type' => $type, 'localizable' => true]))->toBe(FieldTier::Tier3);
})->with('tier3 types');

it('classifies skip types', function (string $type) {
    expect(FieldClassifier::classify(['type' => $type, 'localizable' => true]))->toBe(FieldTier::Skip);
})->with('skip types');

// ── localizable / translatable guards ─────────────────────────────────────────

it('skips fields with localizable false', function () {
    expect(FieldClassifier::classify(['type' => 'text', 'localizable' => false]))->toBe(FieldTier::Skip);
});

it('skips fields with missing localizable key', function () {
    expect(FieldClassifier::classify(['type' => 'text']))->toBe(FieldTier::Skip);
});

it('skips tier 1 fields with translatable false', function () {
    expect(FieldClassifier::classify(['type' => 'text', 'localizable' => true, 'translatable' => false]))->toBe(FieldTier::Skip);
});

it('skips tier 2 fields with translatable false', function () {
    expect(FieldClassifier::classify(['type' => 'replicator', 'localizable' => true, 'translatable' => false]))->toBe(FieldTier::Skip);
});

it('skips tier 3 fields with translatable false', function () {
    expect(FieldClassifier::classify(['type' => 'bard', 'localizable' => true, 'translatable' => false]))->toBe(FieldTier::Skip);
});

// ── nested classification (inside grid/replicator) ──────────────────────────

it('classifies nested text without localizable key as tier 1', function () {
    expect(FieldClassifier::classifyNested(['type' => 'text']))->toBe(FieldTier::Tier1);
});

it('skips nested fields with localizable false', function () {
    expect(FieldClassifier::classifyNested(['type' => 'text', 'localizable' => false]))->toBe(FieldTier::Skip);
});
