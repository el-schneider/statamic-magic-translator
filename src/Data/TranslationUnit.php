<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Data;

final readonly class TranslationUnit
{
    public function __construct(
        public string $path,
        public string $text,
        public TranslationFormat $format,
        public ?string $translatedText = null,
        public array $markMap = [],
    ) {}

    public function withTranslation(string $translatedText): self
    {
        return new self(
            path: $this->path,
            text: $this->text,
            format: $this->format,
            translatedText: $translatedText,
            markMap: $this->markMap,
        );
    }
}
