<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Services;

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use ElSchneider\ContentTranslator\Exceptions\ProviderAuthException;
use ElSchneider\ContentTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\ContentTranslator\Exceptions\ProviderResponseInvalidException;
use ElSchneider\ContentTranslator\Exceptions\ProviderUnavailableException;
use InvalidArgumentException;
use Prism\Prism\Contracts\Schema as PrismSchema;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

final class PrismTranslationService implements TranslationService
{
    public function __construct(
        private readonly PromptResolver $promptResolver,
    ) {}

    /**
     * Translate an array of TranslationUnits using a Prism-powered LLM.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    public function translate(array $units, string $sourceLocale, string $targetLocale): array
    {
        if ($units === []) {
            return [];
        }

        $maxUnits = $this->resolveChunkSize(config('statamic.content-translator.max_units_per_request'));

        if ($maxUnits !== null && count($units) > $maxUnits) {
            return $this->translateInChunks($units, $sourceLocale, $targetLocale, $maxUnits);
        }

        return $this->sendRequest($units, $sourceLocale, $targetLocale);
    }

    /**
     * Split units into chunks and translate each chunk separately.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    private function translateInChunks(array $units, string $sourceLocale, string $targetLocale, int $chunkSize): array
    {
        $chunks = array_chunk($units, $chunkSize);
        $translated = [];

        foreach ($chunks as $chunk) {
            $translated = array_merge($translated, $this->sendRequest($chunk, $sourceLocale, $targetLocale));
        }

        return $translated;
    }

    /**
     * Send a single structured request to the LLM and map the results back.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    private function sendRequest(array $units, string $sourceLocale, string $targetLocale): array
    {
        $provider = (string) config('statamic.content-translator.prism.provider');
        $model = (string) config('statamic.content-translator.prism.model');
        $context = [
            'provider' => $provider,
            'model' => $model,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'unit_count' => count($units),
        ];

        $systemPrompt = $this->promptResolver->resolve('system', $sourceLocale, $targetLocale, $units);
        $userPromptIntro = $this->promptResolver->resolve('user', $sourceLocale, $targetLocale, $units);

        $payload = array_map(
            fn (TranslationUnit $unit) => ['id' => $unit->path, 'text' => $unit->text],
            $units
        );

        $userPrompt = $this->buildUserPrompt($userPromptIntro, $payload, $provider);

        try {
            $response = Prism::structured()
                ->using($provider, $model)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->withSchema($this->buildSchema($provider))
                ->asStructured();
        } catch (PrismException $exception) {
            throw $this->mapPrismException($exception, $context);
        }

        return $this->mapResponse($units, $response->structured, $context);
    }

    /**
     * Build the structured output schema.
     *
     * Most providers accept an array root (`[{id, text}]`). OpenAI requires
     * an object root, so we wrap the array under a `translations` key.
     */
    private function buildSchema(string $provider): PrismSchema
    {
        $unitSchema = $this->buildUnitSchema();

        // OpenAI structured mode requires an object schema as the root type.
        if ($provider === 'openai') {
            return new ObjectSchema(
                name: 'translations_payload',
                description: 'Translation payload grouped under the translations key',
                properties: [
                    new ArraySchema(
                        name: 'translations',
                        description: 'Array of translated content units',
                        items: $unitSchema,
                    ),
                ],
                requiredFields: ['translations'],
            );
        }

        return new ArraySchema(
            name: 'translations',
            description: 'Array of translated content units',
            items: $unitSchema,
        );
    }

    private function buildUnitSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'unit',
            description: 'A translated content unit',
            properties: [
                new StringSchema('id', 'The unit path identifier (unchanged)'),
                new StringSchema('text', 'The translated text content'),
            ],
            requiredFields: ['id', 'text'],
        );
    }

    /**
     * @param  array<int, array{id: string, text: string}>  $payload
     */
    private function buildUserPrompt(string $intro, array $payload, string $provider): string
    {
        $responsePayload = $provider === 'openai'
            ? ['translations' => $payload]
            : $payload;

        if ($provider === 'openai') {
            $intro .= "\nFor this provider, return a JSON object with a `translations` array.";
        }

        $json = json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Failed to encode translation payload as JSON.');
        }

        return $intro."\n\n".$json;
    }

    /**
     * Map the structured response back to TranslationUnit objects.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    private function mapResponse(array $units, mixed $structured, array $context = []): array
    {
        $translations = $this->extractTranslations($structured, $context);

        // Index translations by id for fast lookup
        $translationsById = [];
        foreach ($translations as $item) {
            if (isset($item['id'], $item['text'])) {
                $translationsById[$item['id']] = $item['text'];
            }
        }

        return array_map(function (TranslationUnit $unit) use ($translationsById, $context) {
            if (! array_key_exists($unit->path, $translationsById)) {
                throw new ProviderResponseInvalidException(
                    sprintf('Missing translation for unit id [%s].', $unit->path),
                    context: array_merge($context, ['unit_id' => $unit->path])
                );
            }

            return $unit->withTranslation((string) $translationsById[$unit->path]);
        }, $units);
    }

    /**
     * @return array<int, array{id: string, text: string}>
     */
    private function extractTranslations(mixed $structured, array $context = []): array
    {
        if (is_array($structured) && array_is_list($structured)) {
            return $structured;
        }

        if (
            is_array($structured)
            && isset($structured['translations'])
            && is_array($structured['translations'])
            && array_is_list($structured['translations'])
        ) {
            return $structured['translations'];
        }

        throw new ProviderResponseInvalidException(
            'Invalid structured response payload from Prism.',
            context: array_merge($context, [
                'response_type' => get_debug_type($structured),
                'response_keys' => is_array($structured) && ! array_is_list($structured) ? array_keys($structured) : [],
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function mapPrismException(PrismException $exception, array $context): ProviderUnavailableException|ProviderRateLimitedException|ProviderAuthException|ProviderResponseInvalidException
    {
        if ($exception instanceof PrismStructuredDecodingException) {
            return new ProviderResponseInvalidException(
                'Prism returned a malformed structured response.',
                $exception,
                $context
            );
        }

        $statusCode = $this->extractStatusCode($exception);
        $errorContext = array_merge($context, ['status_code' => $statusCode]);
        $haystack = $this->exceptionHaystack($exception);

        if (
            $statusCode === 401
            || $statusCode === 403
            || preg_match('/\b(401|403)\b|unauthori(?:s|z)ed|forbidden|authentication|authorization|api(?:_|-| )key/', $haystack) === 1
        ) {
            return new ProviderAuthException('Prism provider authentication failed.', $exception, $errorContext);
        }

        if (
            $statusCode === 429
            || $exception instanceof PrismRateLimitedException
            || preg_match('/\b429\b|rate limit|too many requests|quota exceeded/', $haystack) === 1
        ) {
            return new ProviderRateLimitedException('Prism provider rate limited the request.', $exception, $errorContext);
        }

        if (
            ($statusCode !== null && $statusCode >= 500 && $statusCode < 600)
            || preg_match('/\b5\d{2}\b|connection|connect(?:ion)?|timeout|timed out|temporarily unavailable|server error|overloaded|network/', $haystack) === 1
        ) {
            return new ProviderUnavailableException('Prism provider is temporarily unavailable.', $exception, $errorContext);
        }

        return new ProviderUnavailableException('Prism request failed.', $exception, $errorContext);
    }

    private function extractStatusCode(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $code = $current->getCode();

            if (is_int($code) && $code >= 100 && $code <= 599) {
                return $code;
            }
        }

        $haystack = $this->exceptionHaystack($exception);

        if (preg_match('/\b([1-5]\d{2})\b/', $haystack, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function exceptionHaystack(Throwable $exception): string
    {
        $parts = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $parts[] = $current::class;
            $parts[] = $current->getMessage();
        }

        return mb_strtolower(implode(' | ', array_filter($parts)));
    }

    private function resolveChunkSize(mixed $configured): ?int
    {
        if ($configured === null || $configured === '') {
            return null;
        }

        if (is_string($configured) && ctype_digit($configured)) {
            $configured = (int) $configured;
        }

        if (! is_int($configured) || $configured <= 0) {
            throw new InvalidArgumentException('statamic.content-translator.max_units_per_request must be a positive integer or null.');
        }

        return $configured;
    }
}
