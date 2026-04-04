<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Services;

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use InvalidArgumentException;

final class TranslationServiceFactory
{
    public function make(): TranslationService
    {
        $service = config('statamic.content-translator.service');

        return match ($service) {
            'prism' => app(PrismTranslationService::class),
            'deepl' => app(DeepLTranslationService::class),
            default => throw new InvalidArgumentException("Unknown translation service: [{$service}]"),
        };
    }
}
