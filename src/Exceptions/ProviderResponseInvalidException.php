<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Exceptions;

final class ProviderResponseInvalidException extends MagicTranslatorException
{
    public function errorCode(): string
    {
        return 'provider_response_invalid';
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
