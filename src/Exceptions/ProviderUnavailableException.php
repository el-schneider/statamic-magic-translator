<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Exceptions;

final class ProviderUnavailableException extends MagicTranslatorException
{
    public function errorCode(): string
    {
        return 'provider_unavailable';
    }

    public function messageKey(): string
    {
        return 'magic-translator::messages.error_'.$this->errorCode();
    }

    public function retryable(): bool
    {
        return true;
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
