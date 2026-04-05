<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Exceptions;

final class ProviderNotConfiguredException extends MagicTranslatorException
{
    public function errorCode(): string
    {
        return 'provider_not_configured';
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
