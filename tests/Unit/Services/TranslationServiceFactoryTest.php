<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Exceptions\TranslationConfigException;
use ElSchneider\MagicTranslator\Services\DeepLTranslationService;
use ElSchneider\MagicTranslator\Services\PrismTranslationService;
use ElSchneider\MagicTranslator\Services\TranslationServiceFactory;

uses(Tests\TestCase::class);

it('creates prism service when configured', function () {
    config(['statamic.magic-translator.service' => 'prism']);
    $service = app(TranslationServiceFactory::class)->make();
    expect($service)->toBeInstanceOf(PrismTranslationService::class);
});

it('creates deepl service when configured', function () {
    config(['statamic.magic-translator.service' => 'deepl']);
    $service = app(TranslationServiceFactory::class)->make();
    expect($service)->toBeInstanceOf(DeepLTranslationService::class);
});

it('throws for unknown service', function () {
    config(['statamic.magic-translator.service' => 'unknown']);
    app(TranslationServiceFactory::class)->make();
})->throws(TranslationConfigException::class);
