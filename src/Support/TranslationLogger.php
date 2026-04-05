<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Support;

use ElSchneider\MagicTranslator\Exceptions\MagicTranslatorException;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TranslationLogger
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function error(MagicTranslatorException $exception, array $extra = []): void
    {
        $level = $exception->retryable() ? 'warning' : 'error';
        $context = array_merge($exception->context(), $extra, [
            'error_code' => $exception->errorCode(),
            'retryable' => $exception->retryable(),
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);

        Log::{$level}(sprintf('[magic-translator] %s: %s', $exception->errorCode(), $exception->getMessage()), $context);
    }

    /**
     * Log a debug event at debug level. Gated by LOG_LEVEL only.
     *
     * @param  array<string, mixed>  $context
     */
    public static function debug(string $event, array $context = []): void
    {
        Log::debug('[magic-translator] '.$event, $context);
    }

    /**
     * Log a request/response payload (prompts, raw responses, translated text).
     *
     * Gated by LOG_LEVEL=debug AND `log_payloads=true` because payloads contain
     * user content and may include sensitive data.
     *
     * @param  array<string, mixed>  $context
     */
    public static function payload(string $event, array $context = []): void
    {
        if (! config('statamic.magic-translator.log_payloads')) {
            return;
        }

        Log::debug('[magic-translator] '.$event, $context);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function unexpected(Throwable $exception, array $extra = []): void
    {
        Log::error('[magic-translator] unexpected_error: '.$exception->getMessage(), array_merge($extra, [
            'error_code' => 'unexpected_error',
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]));
    }
}
