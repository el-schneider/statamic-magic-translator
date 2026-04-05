<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Exceptions;

use RuntimeException;
use Throwable;

abstract class MagicTranslatorException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, ?Throwable $previous = null, private readonly array $context = [])
    {
        parent::__construct($message, previous: $previous);
    }

    abstract public function errorCode(): string;

    abstract public function messageKey(): string;

    abstract public function retryable(): bool;

    abstract public function httpStatus(): int;

    /**
     * @return array<string, mixed>
     */
    final public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{code: string, message: string, message_key: string, retryable: bool}
     */
    final public function toApiError(): array
    {
        return [
            'code' => $this->errorCode(),
            'message' => (string) __($this->messageKey()),
            'message_key' => $this->messageKey(),
            'retryable' => $this->retryable(),
        ];
    }
}
