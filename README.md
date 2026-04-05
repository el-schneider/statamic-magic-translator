# Statamic Content Translator

> Translate Statamic entry content across multi-site localizations using LLMs or DeepL — with full support for Bard, Replicator, Grid, and deeply nested content structures.

## Features

- **LLM & DeepL support**: Translate via any [Prism](https://prismphp.com)-supported provider (OpenAI, Anthropic, Gemini, Mistral, Ollama, …) or DeepL's dedicated translation API
- **Deep content awareness**: Recursively walks Bard fields, Replicators, Grids, and Tables — translates text while preserving structure, marks, and custom extensions
- **Async processing**: Every translation runs as a queued job with retry and backoff
- **Sidebar UI**: Auto-injected fieldtype with a translation dialog — pick target locales, track progress per locale, retry failures inline
- **Bulk actions**: Translate multiple entries at once from collection listings
- **Staleness detection**: Badges in the Sites panel show which localizations are up-to-date, outdated, or missing
- **Customizable prompts**: Blade views for system/user prompts, with per-language overrides
- **Statamic v5 + v6**

## Installation

```bash
composer require el-schneider/statamic-content-translator
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=statamic-content-translator-config
```

## Configuration

### 1. Exclude blueprints (optional)

By default, the addon auto-injects its fieldtype into entry blueprints. Use
`exclude_blueprints` to opt out specific blueprints or whole collections:

```php
// config/content-translator.php

'exclude_blueprints' => [
    'pages.redirect', // exact blueprint
    'blog.*',         // all blueprints in a collection
],
```

### 2. Choose a translation service

#### Prism (LLMs)

Set your provider and model:

```env
CONTENT_TRANSLATOR_SERVICE=prism
CONTENT_TRANSLATOR_PROVIDER=openai
CONTENT_TRANSLATOR_MODEL=gpt-4.1-mini
OPENAI_API_KEY=sk-...
```

Any Prism-supported provider works — Anthropic, OpenAI, Gemini, Mistral, Ollama, etc. Just add the provider's API key to your `.env` and reference it in [Prism's config](https://prismphp.com/getting-started/installation.html).

#### DeepL

```env
CONTENT_TRANSLATOR_SERVICE=deepl
DEEPL_API_KEY=your-deepl-key
```

DeepL-specific options:

```php
'deepl' => [
    'api_key' => env('DEEPL_API_KEY'),
    'formality' => 'default', // 'more', 'less', 'prefer_more', 'prefer_less'
    'overrides' => [
        'de' => ['formality' => 'prefer_more'],
    ],
],
```

### 3. Set up a queue worker

Translation jobs run asynchronously. You need a queue driver other than `sync` and a running worker:

```bash
php artisan queue:work
```

Optionally configure a dedicated queue:

```env
CONTENT_TRANSLATOR_QUEUE_CONNECTION=redis
CONTENT_TRANSLATOR_QUEUE_NAME=translations
```

## Usage

### Translating a single entry

1. Open an entry in the control panel
2. Click **Translate** in the sidebar
3. Select target locales and options
4. Click **Translate selected**

Each locale shows its own progress indicator. Failed translations display the error inline with a retry button.

### Bulk translation

1. Select entries in a collection listing
2. Choose **Translate** from the actions menu
3. Pick target locales and options in the dialog

### Translation dialog options

| Option | Description |
|:---|:---|
| **Source locale** | Defaults to origin entry. Can be changed to translate from any existing localization. |
| **Generate slugs** | Auto-generate slugs from the translated title. |
| **Overwrite existing** | When disabled (default), locales with existing translations are unchecked to prevent accidental overwrites. |

### Staleness badges

The Sites panel in the sidebar shows translation status per locale:

- **2h ago** — translated, up-to-date
- **⚠️ outdated** — origin has been updated since last translation
- **—** — localization exists but was never machine-translated

## Content Structure Support

The addon handles all idiomatic Statamic content patterns:

| Fieldtype | Handling |
|:---|:---|
| **Text, Textarea** | Translated as plain text |
| **Markdown** | Translated as markdown (formatting preserved) |
| **Bard** | Body text serialized with inline HTML tags, sets extracted recursively. Custom marks and extensions (e.g., Bard Texstyle) are preserved — the ProseMirror structure is never round-tripped through HTML. |
| **Bard (raw markdown)** | Starter kit entries storing markdown instead of ProseMirror JSON are detected and translated as markdown. |
| **Replicator** | Each set's fields are recursively extracted and translated. |
| **Grid** | Each row's columns are recursively extracted and translated. |
| **Table** | Each cell is translated as plain text. |
| **Link** | `text` property is translated, `url` is preserved. |
| **Assets, Toggle, Integer, Select, …** | Skipped (non-text fields are never translated). |

Fields marked `localizable: false` in the blueprint are always skipped. Individual fields can be excluded with `translatable: false` in the field config.

Deeply nested structures (Bard → set → Replicator → set → Bard → …) work to arbitrary depth.

## Customizing Prompts

Translation prompts are Blade views. Publish them to customize:

```bash
php artisan vendor:publish --tag=content-translator-views
```

This copies prompt templates to `resources/views/vendor/content-translator/prompts/`.

### Per-language prompt overrides

Different languages may need different instructions (e.g., formal "Sie" in German, polite form in Japanese):

```php
// config/content-translator.php

'prism' => [
    'prompts' => [
        'system' => 'content-translator::prompts.system',
        'user' => 'content-translator::prompts.user',
        'overrides' => [
            'de' => ['system' => 'content-translator::prompts.system-de'],
            'ja' => ['system' => 'content-translator::prompts.system-ja'],
        ],
    ],
],
```

Create the override views (e.g., `resources/views/vendor/content-translator/prompts/system-de.blade.php`) with language-specific instructions.

### Available prompt variables

| Variable | Example |
|:---|:---|
| `$sourceLocale` | `en` |
| `$targetLocale` | `de` |
| `$sourceLocaleName` | `English` |
| `$targetLocaleName` | `German` |
| `$hasHtmlUnits` | `true` (Bard content present) |
| `$hasMarkdownUnits` | `true` (Markdown fields present) |

## Events

Hook into the translation lifecycle:

### `BeforeEntryTranslation`

Fired before extraction. Modify the `$units` array to exclude or alter translation units.

```php
use ElSchneider\ContentTranslator\Events\BeforeEntryTranslation;

Event::listen(BeforeEntryTranslation::class, function ($event) {
    // $event->entry — the source entry
    // $event->targetSite — target locale handle
    // $event->units — mutable array of TranslationUnit objects
});
```

### `AfterEntryTranslation`

Fired after translation, before save. Modify `$translatedData` to post-process the result.

```php
use ElSchneider\ContentTranslator\Events\AfterEntryTranslation;

Event::listen(AfterEntryTranslation::class, function ($event) {
    // $event->entry — the source entry
    // $event->targetSite — target locale handle
    // $event->translatedData — mutable array of translated entry data
});
```

## Custom Translation Service

Implement the `TranslationService` contract to add your own backend (Google Translate, etc.):

```php
use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Data\TranslationUnit;

class GoogleTranslateService implements TranslationService
{
    public function translate(array $units, string $sourceLocale, string $targetLocale): array
    {
        // $units is an array of TranslationUnit objects
        // Return the same array with translatedText set on each unit
        return array_map(fn (TranslationUnit $unit) => $unit->withTranslation(
            $this->callGoogleApi($unit->text, $sourceLocale, $targetLocale)
        ), $units);
    }
}
```

Bind it in a service provider:

```php
$this->app->bind(TranslationService::class, GoogleTranslateService::class);
```

## Requirements

- PHP 8.2+
- Statamic 5.0+ or 6.0+
- An async queue driver (`database`, `redis`, `sqs`, …) with a running worker
- At least one translation provider configured (LLM API key or DeepL API key)

## License

MIT
