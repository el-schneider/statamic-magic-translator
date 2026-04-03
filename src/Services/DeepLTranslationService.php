<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Services;

use DeepL\TranslateTextOptions;
use DeepL\Translator;
use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use InvalidArgumentException;
use RuntimeException;

final class DeepLTranslationService implements TranslationService
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    /**
     * Translate an array of TranslationUnits using the DeepL API.
     *
     * Units are concatenated into a single XML string using `<ct-unit id="N">` tags,
     * sent to DeepL with `tag_handling: xml`, then split back apart by id.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    public function translate(array $units, string $sourceLocale, string $targetLocale): array
    {
        if ($units === []) {
            return [];
        }

        $maxUnits = $this->resolveChunkSize(config('content-translator.max_units_per_request'));

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
        $translated = [];

        foreach (array_chunk($units, $chunkSize) as $chunk) {
            $translated = array_merge($translated, $this->sendRequest($chunk, $sourceLocale, $targetLocale));
        }

        return $translated;
    }

    /**
     * Translate one batch of units in a single DeepL API request.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    private function sendRequest(array $units, string $sourceLocale, string $targetLocale): array
    {
        $concatenated = $this->concatenateUnits($units);
        $deeplSourceLocale = $this->mapSourceLocale($sourceLocale);
        $deeplTargetLocale = $this->mapTargetLocale($targetLocale);

        $options = [
            TranslateTextOptions::TAG_HANDLING => 'xml',
            TranslateTextOptions::FORMALITY => $this->resolveFormality($targetLocale),
        ];

        $result = $this->translator->translateText(
            $concatenated,
            $deeplSourceLocale,
            $deeplTargetLocale,
            $options
        );

        return $this->parseResponse($units, $result->text);
    }

    /**
     * Build a single XML string by wrapping each unit in <ct-unit id="N"> tags.
     *
     * IDs are always 0-based and sequential within a single batch so they restart
     * at 0 for every chunk.
     *
     * @param  TranslationUnit[]  $units
     */
    private function concatenateUnits(array $units): string
    {
        $parts = [];

        foreach (array_values($units) as $index => $unit) {
            $parts[] = "<ct-unit id=\"{$index}\">{$unit->text}</ct-unit>";
        }

        return implode('', $parts);
    }

    /**
     * Parse the translated XML string back into TranslationUnit objects.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    private function parseResponse(array $units, string $responseText): array
    {
        preg_match_all(
            '/<ct-unit\b[^>]*\bid\s*=\s*([\'\"])(\d+)\1[^>]*>(.*?)<\/ct-unit\s*>/si',
            $responseText,
            $matches,
            PREG_SET_ORDER
        );

        $translatedByIndex = [];

        foreach ($matches as $match) {
            $translatedByIndex[(int) $match[2]] = $match[3];
        }

        $reindexed = array_values($units);
        $result = [];

        foreach ($reindexed as $index => $unit) {
            if (! array_key_exists($index, $translatedByIndex)) {
                throw new RuntimeException(
                    sprintf('Missing translation for unit index [%d] (path: %s).', $index, $unit->path)
                );
            }

            $result[] = $unit->withTranslation($translatedByIndex[$index]);
        }

        return $result;
    }

    /**
     * Resolve the formality setting for the given target locale.
     *
     * Checks per-language overrides in config first, then falls back to
     * the global formality setting.
     */
    private function resolveFormality(string $targetLocale): string
    {
        $baseLang = mb_strtolower(explode('-', str_replace('_', '-', $targetLocale))[0]);
        $overrides = config('content-translator.deepl.overrides', []);

        if (isset($overrides[$baseLang]['formality'])) {
            return (string) $overrides[$baseLang]['formality'];
        }

        return (string) config('content-translator.deepl.formality', 'default');
    }

    /**
     * Map a Statamic source locale to a DeepL source language code.
     *
     * DeepL source languages only need the base code (e.g. 'EN', 'DE'),
     * never a regional variant.
     */
    private function mapSourceLocale(string $locale): string
    {
        $base = explode('-', str_replace('_', '-', $locale))[0];

        return mb_strtoupper($base);
    }

    /**
     * Map a Statamic target locale to a DeepL target language code.
     *
     * Certain languages require an explicit regional variant:
     * - English: defaults to EN-US (must specify EN-US or EN-GB)
     * - Portuguese: defaults to PT-PT (must specify PT-PT or PT-BR)
     * - Chinese: defaults to ZH-HANS (must specify ZH-HANS or ZH-HANT)
     */
    private function mapTargetLocale(string $locale): string
    {
        $normalized = mb_strtolower(str_replace('_', '-', $locale));

        return match ($normalized) {
            // English — explicit variant required
            'en', 'en-us' => 'EN-US',
            'en-gb' => 'EN-GB',
            // Portuguese — explicit variant required
            'pt', 'pt-pt' => 'PT-PT',
            'pt-br' => 'PT-BR',
            // Chinese — explicit variant required
            'zh', 'zh-cn', 'zh-hans' => 'ZH-HANS',
            'zh-tw', 'zh-hant' => 'ZH-HANT',
            // Everything else: uppercase the code as-is
            default => $this->normalizeLocaleToDeepL($normalized),
        };
    }

    /**
     * Uppercase a locale code while preserving the hyphen separator.
     *
     * Examples: 'de' → 'DE', 'de-at' → 'DE-AT'
     */
    private function normalizeLocaleToDeepL(string $locale): string
    {
        $parts = explode('-', $locale, 2);

        if (count($parts) === 2) {
            return mb_strtoupper($parts[0]).'-'.mb_strtoupper($parts[1]);
        }

        return mb_strtoupper($parts[0]);
    }

    /**
     * Validate and resolve the chunk size from config.
     *
     * Returns null if chunking is disabled, a positive int otherwise.
     *
     * @throws InvalidArgumentException if the configured value is invalid.
     */
    private function resolveChunkSize(mixed $configured): ?int
    {
        if ($configured === null || $configured === '') {
            return null;
        }

        if (is_string($configured) && ctype_digit($configured)) {
            $configured = (int) $configured;
        }

        if (! is_int($configured) || $configured <= 0) {
            throw new InvalidArgumentException(
                'content-translator.max_units_per_request must be a positive integer or null.'
            );
        }

        return $configured;
    }
}
