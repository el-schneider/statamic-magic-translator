<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Services;

use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Exceptions\TranslationConfigException;

final class TranslationServiceFactory
{
    public function make(): TranslationService
    {
        $service = config('statamic.magic-translator.service');

        return match ($service) {
            'prism' => app(PrismTranslationService::class),
            'deepl' => app(DeepLTranslationService::class),
            default => throw new TranslationConfigException("Unknown translation service: [{$service}]"),
        };
    }
}
