<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Services\DeepLTranslationService;
use ElSchneider\ContentTranslator\Services\PrismTranslationService;
use ElSchneider\ContentTranslator\Services\TranslationServiceFactory;

uses(Tests\TestCase::class);

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
