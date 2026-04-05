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
