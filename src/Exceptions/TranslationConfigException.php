<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Exceptions;

final class TranslationConfigException extends ContentTranslatorException
{
    public function errorCode(): string
    {
        return 'translation_config_invalid';
    }

    public function messageKey(): string
    {
        return 'content-translator::messages.error_'.$this->errorCode();
    }

    public function retryable(): bool
    {
        return false;
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
