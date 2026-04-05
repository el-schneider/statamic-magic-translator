<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Exceptions\TranslationConfigException;
use ElSchneider\ContentTranslator\Services\DeepLTranslationService;
use ElSchneider\ContentTranslator\Services\PrismTranslationService;
use ElSchneider\ContentTranslator\Services\TranslationServiceFactory;

uses(Tests\TestCase::class);

it('creates prism service when configured', function () {
    config(['statamic.content-translator.service' => 'prism']);
    $service = app(TranslationServiceFactory::class)->make();
    expect($service)->toBeInstanceOf(PrismTranslationService::class);
});

it('creates deepl service when configured', function () {
    config(['statamic.content-translator.service' => 'deepl']);
    $service = app(TranslationServiceFactory::class)->make();
    expect($service)->toBeInstanceOf(DeepLTranslationService::class);
});

it('throws for unknown service', function () {
    config(['statamic.content-translator.service' => 'unknown']);
    app(TranslationServiceFactory::class)->make();
})->throws(TranslationConfigException::class);
