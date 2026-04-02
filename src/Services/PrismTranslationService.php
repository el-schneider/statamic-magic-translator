<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Services;

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use RuntimeException;

final class PrismTranslationService implements TranslationService
{
    public function translate(array $units, string $sourceLocale, string $targetLocale): array
    {
        throw new RuntimeException('Not implemented');
    }
}
