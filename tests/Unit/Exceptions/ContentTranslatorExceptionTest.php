<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Exceptions\ContentTranslatorException;
use ElSchneider\ContentTranslator\Exceptions\ProviderAuthException;
use ElSchneider\ContentTranslator\Exceptions\ProviderNotConfiguredException;
use ElSchneider\ContentTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\ContentTranslator\Exceptions\ProviderResponseInvalidException;
use ElSchneider\ContentTranslator\Exceptions\ProviderUnavailableException;
use ElSchneider\ContentTranslator\Exceptions\TranslationConfigException;
use ElSchneider\ContentTranslator\Exceptions\TranslationDispatchException;

uses(Tests\TestCase::class);

dataset('content translator exceptions', [
    'provider not configured' => [
        fn (Throwable $previous) => new ProviderNotConfiguredException('Provider missing.', $previous, ['provider' => 'prism']),
        'provider_not_configured',
        'content-translator::messages.error_provider_not_configured',
        false,
        500,
        'Translation service is not configured.',
    ],
    'provider auth failed' => [
        fn (Throwable $previous) => new ProviderAuthException('Authentication failed.', $previous, ['provider' => 'prism']),
        'provider_auth_failed',
        'content-translator::messages.error_provider_auth_failed',
        false,
        502,
        'Translation service authentication failed.',
    ],
    'provider rate limited' => [
        fn (Throwable $previous) => new ProviderRateLimitedException('Rate limit exceeded.', $previous, ['provider' => 'prism']),
        'provider_rate_limited',
        'content-translator::messages.error_provider_rate_limited',
        true,
        502,
        'Translation service rate limit exceeded. Please try again later.',
    ],
    'provider unavailable' => [
        fn (Throwable $previous) => new ProviderUnavailableException('Provider unavailable.', $previous, ['provider' => 'prism']),
        'provider_unavailable',
        'content-translator::messages.error_provider_unavailable',
        true,
        502,
        'Translation service is temporarily unavailable. Please try again later.',
    ],
    'provider response invalid' => [
        fn (Throwable $previous) => new ProviderResponseInvalidException('Invalid response.', $previous, ['provider' => 'prism']),
        'provider_response_invalid',
        'content-translator::messages.error_provider_response_invalid',
        false,
        502,
        'Translation service returned an invalid response.',
    ],
    'translation config invalid' => [
        fn (Throwable $previous) => new TranslationConfigException('Invalid configuration.', $previous, ['service' => 'unknown']),
        'translation_config_invalid',
        'content-translator::messages.error_translation_config_invalid',
        false,
        500,
        'Translation configuration is invalid.',
    ],
    'translation dispatch failed' => [
        fn (Throwable $previous) => new TranslationDispatchException('Dispatch failed.', $previous, ['entry' => 'home']),
        'translation_dispatch_failed',
        'content-translator::messages.error_translation_dispatch_failed',
        false,
        500,
        'Failed to dispatch translation job.',
    ],
]);

it('exposes stable error contracts for domain exceptions', function (
    Closure $makeException,
    string $expectedCode,
    string $expectedMessageKey,
    bool $expectedRetryable,
    int $expectedHttpStatus,
    string $expectedApiMessage,
) {
    $previous = new RuntimeException('Previous error.');

    /** @var ContentTranslatorException $exception */
    $exception = $makeException($previous);

    expect($exception->errorCode())->toBe($expectedCode)
        ->and($exception->messageKey())->toBe($expectedMessageKey)
        ->and($exception->retryable())->toBe($expectedRetryable)
        ->and($exception->httpStatus())->toBe($expectedHttpStatus)
        ->and($exception->context())->not->toBeEmpty()
        ->and($exception->toApiError())->toBe([
            'code' => $expectedCode,
            'message' => $expectedApiMessage,
            'message_key' => $expectedMessageKey,
            'retryable' => $expectedRetryable,
        ]);
})->with('content translator exceptions');

it('stores the previous throwable and context on the base exception', function () {
    $previous = new RuntimeException('Original provider failure.');
    $exception = new ProviderUnavailableException(
        'Provider unavailable.',
        $previous,
        ['provider' => 'prism', 'model' => 'gpt-4o']
    );

    expect($exception->getPrevious())->toBe($previous)
        ->and($exception->context())->toBe([
            'provider' => 'prism',
            'model' => 'gpt-4o',
        ]);
});
