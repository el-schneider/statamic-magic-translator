<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Services;

use DeepL\AuthorizationException;
use DeepL\ConnectionException;
use DeepL\DeepLException;
use DeepL\QuotaExceededException;
use DeepL\TooManyRequestsException;
use DeepL\TranslateTextOptions;
use DeepL\Translator;
use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Data\TranslationFormat;
use ElSchneider\MagicTranslator\Data\TranslationUnit;
use ElSchneider\MagicTranslator\Exceptions\ProviderAuthException;
use ElSchneider\MagicTranslator\Exceptions\ProviderRateLimitedException;
use ElSchneider\MagicTranslator\Exceptions\ProviderResponseInvalidException;
use ElSchneider\MagicTranslator\Exceptions\ProviderUnavailableException;
use ElSchneider\MagicTranslator\Exceptions\SourceContentInvalidException;
use ElSchneider\MagicTranslator\Support\TranslationLogger;
use InvalidArgumentException;

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

        $maxUnits = $this->resolveChunkSize(config('statamic.magic-translator.max_units_per_request'));

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
        $context = [
            'provider' => 'deepl',
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'deepl_source_locale' => $deeplSourceLocale,
            'deepl_target_locale' => $deeplTargetLocale,
            'unit_count' => count($units),
        ];

        $options = [
            TranslateTextOptions::TAG_HANDLING => 'xml',
            TranslateTextOptions::FORMALITY => $this->resolveFormality($targetLocale),
        ];

        if (($glossary = $this->resolveGlossary($targetLocale)) !== null) {
            $options[TranslateTextOptions::GLOSSARY] = $glossary;
            $context['glossary'] = $glossary;
        }

        TranslationLogger::debug('deepl_request', array_merge($context, [
            'character_count' => mb_strlen($concatenated),
        ]));
        TranslationLogger::payload('deepl_request_payload', array_merge($context, [
            'text' => $concatenated,
        ]));

        try {
            $result = $this->translator->translateText(
                $concatenated,
                $deeplSourceLocale,
                $deeplTargetLocale,
                $options
            );
        } catch (AuthorizationException $exception) {
            throw new ProviderAuthException('DeepL authentication failed.', $exception, $context);
        } catch (TooManyRequestsException $exception) {
            throw new ProviderRateLimitedException('DeepL rate limit exceeded.', $exception, $context);
        } catch (QuotaExceededException $exception) {
            throw new ProviderRateLimitedException(
                'DeepL translation quota exceeded.',
                $exception,
                array_merge($context, ['detail' => 'quota_exceeded'])
            );
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailableException('DeepL is temporarily unavailable.', $exception, $context);
        } catch (DeepLException $exception) {
            throw new ProviderUnavailableException('DeepL request failed.', $exception, $context);
        }

        TranslationLogger::debug('deepl_response', array_merge($context, [
            'detected_source_language' => $result->detectedSourceLang,
            'response_length' => mb_strlen($result->text),
        ]));
        TranslationLogger::payload('deepl_response_payload', array_merge($context, [
            'text' => $result->text,
        ]));

        return $this->parseResponse($units, $result->text, $context);
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
            $this->assertXmlSafe($unit, $index);

            $text = $this->escapeUnitText($unit);
            $parts[] = "<ct-unit id=\"{$index}\">{$text}</ct-unit>";
        }

        return implode('', $parts);
    }

    /**
     * Reject characters XML 1.0 cannot carry at all. Escaping does not help for
     * these: control characters other than tab, newline and carriage return are
     * forbidden in XML text, so DeepL would reject the whole chunk with the same
     * opaque parse error. They reach content by way of pasted word processor and
     * PDF text.
     */
    private function assertXmlSafe(TranslationUnit $unit, int $index): void
    {
        if (preg_match('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{FFFE}\x{FFFF}]/u', $unit->text, $match) !== 1) {
            return;
        }

        $codepoint = sprintf('U+%04X', mb_ord($match[0], 'UTF-8'));

        throw new SourceContentInvalidException(
            sprintf('Field [%s] contains a character XML cannot carry (%s).', $unit->path, $codepoint),
            context: [
                'unit_index' => $index,
                'unit_path' => $unit->path,
                'codepoint' => $codepoint,
            ]
        );
    }

    /**
     * Escape unit text so the payload stays well-formed XML. A bare ampersand
     * or angle bracket in editorial content makes DeepL reject the whole
     * request with a tag handling parse error.
     *
     * Html units are markup produced by the bard serializer, which escapes its
     * own text content, so their tags must be left intact.
     */
    private function escapeUnitText(TranslationUnit $unit): string
    {
        if ($unit->format === TranslationFormat::Html) {
            return $unit->text;
        }

        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $unit->text);
    }

    /**
     * Parse the translated XML string back into TranslationUnit objects.
     *
     * @param  TranslationUnit[]  $units
     * @return TranslationUnit[]
     */
    private function parseResponse(array $units, string $responseText, array $context = []): array
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
                throw new ProviderResponseInvalidException(
                    sprintf('Missing translation for unit index [%d] (path: %s).', $index, $unit->path),
                    context: array_merge($context, [
                        'unit_index' => $index,
                        'unit_path' => $unit->path,
                        'response_length' => mb_strlen($responseText),
                    ])
                );
            }

            $result[] = $unit->withTranslation(
                $this->decodeUnitText($unit, $translatedByIndex[$index])
            );
        }

        return $result;
    }

    /**
     * Reverse escapeUnitText() on a translated string. Html units keep their
     * entities because the bard parser decodes them while rebuilding nodes.
     */
    private function decodeUnitText(TranslationUnit $unit, string $translated): string
    {
        if ($unit->format === TranslationFormat::Html) {
            return $translated;
        }

        return html_entity_decode($translated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Resolve the formality setting for the given target locale.
     *
     * Checks per-language overrides in config first, then falls back to
     * the global formality setting.
     */
    private function resolveFormality(string $targetLocale): string
    {
        $baseLang = $this->baseLanguage($targetLocale);
        $overrides = config('statamic.magic-translator.deepl.overrides', []);

        if (isset($overrides[$baseLang]['formality'])) {
            return (string) $overrides[$baseLang]['formality'];
        }

        return (string) config('statamic.magic-translator.deepl.formality', 'default');
    }

    /**
     * Resolve the glossary id for the given target locale.
     *
     * A DeepL glossary is bound to one language pair, so the global glossary
     * only ever fits one target language. A blank override opts a language out
     * of it; otherwise DeepL would reject the request.
     */
    private function resolveGlossary(string $targetLocale): ?string
    {
        $overrides = config('statamic.magic-translator.deepl.overrides', []);
        $override = $overrides[$this->baseLanguage($targetLocale)]['glossary'] ?? null;

        if (is_string($override)) {
            return mb_trim($override) !== '' ? $override : null;
        }

        $global = config('statamic.magic-translator.deepl.glossary');

        return is_string($global) && mb_trim($global) !== '' ? $global : null;
    }

    /**
     * Reduce a Statamic locale to its base language code (`de_DE` -> `de`).
     */
    private function baseLanguage(string $locale): string
    {
        return mb_strtolower(explode('-', str_replace('_', '-', $locale))[0]);
    }

    /**
     * Map a Statamic source locale to a DeepL source language code.
     *
     * DeepL source languages only need the base code (e.g. 'EN', 'DE'),
     * never a regional variant.
     */
    private function mapSourceLocale(string $locale): string
    {
        return mb_strtoupper($this->baseLanguage($locale));
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
            // Everything else: base language code only (DeepL does not
            // support regional variants beyond EN/PT/ZH).
            default => mb_strtoupper(explode('-', $normalized)[0]),
        };
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
                'statamic.magic-translator.max_units_per_request must be a positive integer or null.'
            );
        }

        return $configured;
    }
}
