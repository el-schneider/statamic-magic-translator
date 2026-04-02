<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Services;

use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

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

        $maxUnits = config('content-translator.max_units_per_request');

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
        $systemPrompt = $this->promptResolver->resolve('system', $sourceLocale, $targetLocale, $units);
        $userPromptIntro = $this->promptResolver->resolve('user', $sourceLocale, $targetLocale, $units);

        $payload = array_map(
            fn (TranslationUnit $unit) => ['id' => $unit->path, 'text' => $unit->text],
            $units
        );

        $userPrompt = $userPromptIntro."\n\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = Prism::structured()
            ->using(
                config('content-translator.prism.provider'),
                config('content-translator.prism.model'),
            )
            ->withSystemPrompt($systemPrompt)
            ->withPrompt($userPrompt)
            ->withSchema($this->buildSchema())
            ->asStructured();

        return $this->mapResponse($units, $response->structured ?? []);
    }

    /**
     * Build the structured output schema: array of {id: string, text: string}.
     */
    private function buildSchema(): ArraySchema
    {
        return new ArraySchema(
            name: 'translations',
            description: 'Array of translated content units',
            items: new ObjectSchema(
                name: 'unit',
                description: 'A translated content unit',
                properties: [
                    new StringSchema('id', 'The unit path identifier (unchanged)'),
                    new StringSchema('text', 'The translated text content'),
                ],
                requiredFields: ['id', 'text'],
            ),
        );
    }

    /**
     * Map the structured response back to TranslationUnit objects.
     *
     * @param  TranslationUnit[]  $units
     * @param  array<int, array{id: string, text: string}>  $structured
     * @return TranslationUnit[]
     */
    private function mapResponse(array $units, array $structured): array
    {
        // Index translations by id for fast lookup
        $translationsById = [];
        foreach ($structured as $item) {
            if (isset($item['id'], $item['text'])) {
                $translationsById[$item['id']] = $item['text'];
            }
        }

        return array_map(function (TranslationUnit $unit) use ($translationsById) {
            if (isset($translationsById[$unit->path])) {
                return $unit->withTranslation($translationsById[$unit->path]);
            }

            return $unit;
        }, $units);
    }
}
