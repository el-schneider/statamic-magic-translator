<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Exceptions;

final class TranslationConfigException extends MagicTranslatorException
{
    public function errorCode(): string
    {
        return 'translation_config_invalid';
    }

    public function messageKey(): string
    {
        return 'magic-translator::messages.error_'.$this->errorCode();
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
