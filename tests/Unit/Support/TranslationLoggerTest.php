<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Exceptions\ProviderAuthException;
use ElSchneider\ContentTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\ContentTranslator\Support\TranslationLogger;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('logs retryable content translator exceptions at warning level', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('[content-translator] provider_rate_limited: Rate limit exceeded.');
            expect($context['provider'])->toBe('prism');
            expect($context['entry_id'])->toBe('entry-123');
            expect($context['error_code'])->toBe('provider_rate_limited');
            expect($context['retryable'])->toBeTrue();
            expect($context['exception_class'])->toBe(ProviderRateLimitedException::class);
            expect($context['exception_message'])->toBe('Rate limit exceeded.');

            return true;
        });

    TranslationLogger::error(
        new ProviderRateLimitedException('Rate limit exceeded.', null, ['provider' => 'prism']),
        ['entry_id' => 'entry-123'],
    );
});

it('logs non-retryable content translator exceptions at error level', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('[content-translator] provider_auth_failed: Authentication failed.');
            expect($context['provider'])->toBe('deepl');
            expect($context['error_code'])->toBe('provider_auth_failed');
            expect($context['retryable'])->toBeFalse();
            expect($context['exception_class'])->toBe(ProviderAuthException::class);
            expect($context['exception_message'])->toBe('Authentication failed.');

            return true;
        });

    TranslationLogger::error(
        new ProviderAuthException('Authentication failed.', null, ['provider' => 'deepl']),
    );
});

it('includes structured context fields when logging content translator exceptions', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('[content-translator] provider_rate_limited: Retry later.');
            expect($context)->toHaveKeys(['error_code', 'retryable', 'exception_class', 'exception_message']);
            expect($context['error_code'])->toBe('provider_rate_limited');
            expect($context['retryable'])->toBeTrue();
            expect($context['exception_class'])->toBe(ProviderRateLimitedException::class);

            return true;
        });

    TranslationLogger::error(new ProviderRateLimitedException('Retry later.'));
});

it('logs unexpected exceptions at error level', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('[content-translator] unexpected_error: Boom');
            expect($context['job_id'])->toBe('job-123');
            expect($context['error_code'])->toBe('unexpected_error');
            expect($context['exception_class'])->toBe(RuntimeException::class);
            expect($context['exception_message'])->toBe('Boom');

            return true;
        });

    TranslationLogger::unexpected(new RuntimeException('Boom'), ['job_id' => 'job-123']);
});
