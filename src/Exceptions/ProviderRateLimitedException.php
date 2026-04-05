<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Exceptions;

final class ProviderRateLimitedException extends MagicTranslatorException
{
    public function errorCode(): string
    {
        return 'provider_rate_limited';
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
