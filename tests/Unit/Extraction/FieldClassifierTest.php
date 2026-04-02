<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Extraction\FieldClassifier;
use ElSchneider\ContentTranslator\Extraction\FieldTier;

// ── Tier 1: flat text ─────────────────────────────────────────────────────────

it('classifies text as tier 1', function () {
    expect(FieldClassifier::classify(['type' => 'text', 'localizable' => true]))->toBe(FieldTier::Tier1);
});

it('classifies textarea as tier 1', function () {
    expect(FieldClassifier::classify(['type' => 'textarea', 'localizable' => true]))->toBe(FieldTier::Tier1);
});

it('classifies markdown as tier 1', function () {
    expect(FieldClassifier::classify(['type' => 'markdown', 'localizable' => true]))->toBe(FieldTier::Tier1);
});

it('classifies link as tier 1 (borderline translatable)', function () {
    expect(FieldClassifier::classify(['type' => 'link', 'localizable' => true]))->toBe(FieldTier::Tier1);
});

// ── Tier 2: structural ────────────────────────────────────────────────────────

it('classifies replicator as tier 2', function () {
    expect(FieldClassifier::classify(['type' => 'replicator', 'localizable' => true]))->toBe(FieldTier::Tier2);
});

it('classifies grid as tier 2', function () {
    expect(FieldClassifier::classify(['type' => 'grid', 'localizable' => true]))->toBe(FieldTier::Tier2);
});

it('classifies table as tier 2', function () {
    expect(FieldClassifier::classify(['type' => 'table', 'localizable' => true]))->toBe(FieldTier::Tier2);
});

// ── Tier 3: bard ──────────────────────────────────────────────────────────────

it('classifies bard as tier 3', function () {
    expect(FieldClassifier::classify(['type' => 'bard', 'localizable' => true]))->toBe(FieldTier::Tier3);
});

// ── Tier 4 / Skip ─────────────────────────────────────────────────────────────

it('classifies assets as skip', function () {
    expect(FieldClassifier::classify(['type' => 'assets', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies toggle as skip', function () {
    expect(FieldClassifier::classify(['type' => 'toggle', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies integer as skip', function () {
    expect(FieldClassifier::classify(['type' => 'integer', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies float as skip', function () {
    expect(FieldClassifier::classify(['type' => 'float', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies date as skip', function () {
    expect(FieldClassifier::classify(['type' => 'date', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies color as skip', function () {
    expect(FieldClassifier::classify(['type' => 'color', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies code as skip', function () {
    expect(FieldClassifier::classify(['type' => 'code', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies select as skip', function () {
    expect(FieldClassifier::classify(['type' => 'select', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies radio as skip', function () {
    expect(FieldClassifier::classify(['type' => 'radio', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies checkboxes as skip', function () {
    expect(FieldClassifier::classify(['type' => 'checkboxes', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies entries as skip', function () {
    expect(FieldClassifier::classify(['type' => 'entries', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies terms as skip', function () {
    expect(FieldClassifier::classify(['type' => 'terms', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies users as skip', function () {
    expect(FieldClassifier::classify(['type' => 'users', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies video as skip', function () {
    expect(FieldClassifier::classify(['type' => 'video', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies yaml as skip', function () {
    expect(FieldClassifier::classify(['type' => 'yaml', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies template as skip', function () {
    expect(FieldClassifier::classify(['type' => 'template', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies section as skip', function () {
    expect(FieldClassifier::classify(['type' => 'section', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies slug as skip by default', function () {
    expect(FieldClassifier::classify(['type' => 'slug', 'localizable' => true]))->toBe(FieldTier::Skip);
});

it('classifies unknown field types as skip', function () {
    expect(FieldClassifier::classify(['type' => 'some_custom_unknown_type', 'localizable' => true]))->toBe(FieldTier::Skip);
});

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
