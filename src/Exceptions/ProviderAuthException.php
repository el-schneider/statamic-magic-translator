<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Exceptions;

final class ProviderAuthException extends MagicTranslatorException
{
    public function errorCode(): string
    {
        return 'provider_auth_failed';
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
        return 502;
    }
}
