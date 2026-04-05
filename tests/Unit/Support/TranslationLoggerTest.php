<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Exceptions\ProviderAuthException;
use ElSchneider\ContentTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\ContentTranslator\Support\TranslationLogger;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('logs retryable exceptions at warning level', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBeString();
            expect($context['error_code'])->toBe('provider_rate_limited');
            expect($context['retryable'])->toBeTrue();
            expect($context['entry_id'])->toBe('entry-123');

            return true;
        });

    TranslationLogger::error(
        new ProviderRateLimitedException('Rate limit exceeded.', null, ['provider' => 'prism']),
        ['entry_id' => 'entry-123'],
    );
});

it('logs non-retryable exceptions at error level', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBeString();
            expect($context['error_code'])->toBe('provider_auth_failed');
            expect($context['retryable'])->toBeFalse();

            return true;
        });

    TranslationLogger::error(
        new ProviderAuthException('Authentication failed.', null, ['provider' => 'deepl']),
    );
});

it('logs unexpected exceptions at error level with unexpected_error code', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBeString();
            expect($context['error_code'])->toBe('unexpected_error');
            expect($context['job_id'])->toBe('job-123');

            return true;
        });

    TranslationLogger::unexpected(new RuntimeException('Boom'), ['job_id' => 'job-123']);
});
