<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Services;

use ElSchneider\ContentTranslator\Data\TranslationFormat;
use ElSchneider\ContentTranslator\Data\TranslationUnit;
use Statamic\Facades\Site;

final class PromptResolver
{
    /**
     * Resolve the view name for a given prompt type and target locale.
     * Returns a language-specific override if configured, otherwise the default view.
     */
    public function resolveViewName(string $type, string $targetLocale): string
    {
        $overrides = config("statamic.content-translator.prism.prompts.overrides.{$targetLocale}", []);

        if (isset($overrides[$type])) {
            return $overrides[$type];
        }

        return config("statamic.content-translator.prism.prompts.{$type}");
    }

    /**
     * Resolve and render a prompt view.
     *
     * @param  TranslationUnit[]  $units
     */
    public function resolve(string $type, string $sourceLocale, string $targetLocale, array $units = []): string
    {
        $viewName = $this->resolveViewName($type, $targetLocale);

        return view($viewName, $this->buildViewVars($sourceLocale, $targetLocale, $units))->render();
    }

    /**
     * Build the view variables for prompt rendering.
     *
     * @param  TranslationUnit[]  $units
     * @return array<string, mixed>
     */
    private function buildViewVars(string $sourceLocale, string $targetLocale, array $units): array
    {
        return [
            'sourceLocale' => $sourceLocale,
            'targetLocale' => $targetLocale,
            'sourceLocaleName' => $this->getLocaleName($sourceLocale),
            'targetLocaleName' => $this->getLocaleName($targetLocale),
            'hasHtmlUnits' => $this->hasFormat($units, TranslationFormat::Html),
            'hasMarkdownUnits' => $this->hasFormat($units, TranslationFormat::Markdown),
        ];
    }

    /**
     * Get a human-readable locale name from a site handle or locale code.
     */
    private function getLocaleName(string $locale): string
    {
        $site = Site::get($locale);

        if ($site) {
            return $site->name();
        }

        // Fallback: capitalise the locale code
        return ucfirst($locale);
    }

    /**
     * Check if any units have the given format.
     *
     * @param  TranslationUnit[]  $units
     */
    private function hasFormat(array $units, TranslationFormat $format): bool
    {
        foreach ($units as $unit) {
            if ($unit->format === $format) {
                return true;
            }
        }

        return false;
    }
}
