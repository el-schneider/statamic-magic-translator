<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Extraction;

final readonly class BardSerializerResult
{
    public function __construct(
        public string $text,
        public array $markMap,
    ) {}
}
