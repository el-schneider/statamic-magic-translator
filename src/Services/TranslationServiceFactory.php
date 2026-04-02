<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Services;

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use InvalidArgumentException;

final class TranslationServiceFactory
{
    public function make(): TranslationService
    {
        $service = config('content-translator.service');

        return match ($service) {
            'prism' => new PrismTranslationService,
            'deepl' => new DeepLTranslationService,
            default => throw new InvalidArgumentException("Unknown translation service: [{$service}]"),
        };
    }
}
