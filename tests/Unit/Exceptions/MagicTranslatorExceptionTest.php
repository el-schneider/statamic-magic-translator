<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Exceptions\ProviderUnavailableException;

uses(Tests\TestCase::class);

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

it('formats api errors for provider unavailable exceptions', function () {
    $exception = new ProviderUnavailableException('Provider unavailable.');

    expect($exception->toApiError())->toMatchArray([
        'code' => 'provider_unavailable',
        'message_key' => 'magic-translator::messages.error_provider_unavailable',
        'retryable' => true,
    ])->and($exception->toApiError()['message'])->toBeString()->not->toBe('');
});
