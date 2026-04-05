<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Exceptions;

final class ProviderUnavailableException extends ContentTranslatorException
{
    public function errorCode(): string
    {
        return 'provider_unavailable';
    }

    public function messageKey(): string
    {
        return 'content-translator::messages.error_'.$this->errorCode();
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
