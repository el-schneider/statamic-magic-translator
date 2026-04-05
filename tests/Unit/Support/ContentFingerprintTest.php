<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Support\ContentFingerprint;
use Tests\TestCase;

uses(TestCase::class);

it('returns identical hashes for identical data', function () {
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'meta_description' => ['type' => 'textarea', 'localizable' => true],
    ];

    $data = [
        'title' => 'Hello',
        'meta_description' => 'A short summary',
    ];

    $hashA = ContentFingerprint::compute($data, $fields);
    $hashB = ContentFingerprint::compute($data, $fields);

    expect($hashA)->toBe($hashB);
    expect($hashA)->toStartWith('v1:sha256:');
});

it('changes hash when translatable content changes', function () {
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
    ];

    $hashA = ContentFingerprint::compute(['title' => 'Hello'], $fields);
    $hashB = ContentFingerprint::compute(['title' => 'Hello world'], $fields);

    expect($hashA)->not->toBe($hashB);
});

it('keeps hash stable when only non-translatable fields change', function () {
    $fields = [
        'title' => ['type' => 'text', 'localizable' => true],
        'publish_date' => ['type' => 'date', 'localizable' => true],
    ];

    $hashA = ContentFingerprint::compute([
        'title' => 'Hello',
        'publish_date' => '2026-01-01',
    ], $fields);

    $hashB = ContentFingerprint::compute([
        'title' => 'Hello',
        'publish_date' => '2026-02-01',
    ], $fields);

    expect($hashA)->toBe($hashB);
});

it('is stable across input key ordering differences', function () {
    $fieldsA = [
        'title' => ['type' => 'text', 'localizable' => true],
        'meta_description' => ['type' => 'textarea', 'localizable' => true],
    ];

    $fieldsB = [
        'meta_description' => ['type' => 'textarea', 'localizable' => true],
        'title' => ['type' => 'text', 'localizable' => true],
    ];

    $dataA = [
        'title' => 'Hello',
        'meta_description' => 'A short summary',
    ];

    $dataB = [
        'meta_description' => 'A short summary',
        'title' => 'Hello',
    ];

    $hashA = ContentFingerprint::compute($dataA, $fieldsA);
    $hashB = ContentFingerprint::compute($dataB, $fieldsB);

    expect($hashA)->toBe($hashB);
});
