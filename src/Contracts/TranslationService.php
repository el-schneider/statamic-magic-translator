<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Contracts;

use ElSchneider\MagicTranslator\Data\TranslationUnit;

interface TranslationService
{
    /**
     * Translate an array of TranslationUnits from source to target locale.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    public function translate(array $units, string $sourceLocale, string $targetLocale): array;
}
