<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator;

use DeepL\Translator;
use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Extraction\BardSerializer;
use ElSchneider\ContentTranslator\Extraction\ContentExtractor;
use ElSchneider\ContentTranslator\Reassembly\BardParser;
use ElSchneider\ContentTranslator\Reassembly\ContentReassembler;
use ElSchneider\ContentTranslator\Services\TranslationServiceFactory;
use ElSchneider\ContentTranslator\StatamicActions\TranslateEntryAction;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

final class ServiceProvider extends AddonServiceProvider
{
    protected $actions = [
        TranslateEntryAction::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(Translator::class, function () {
            // Use configured API key; fall back to a non-empty placeholder so
            // the Translator can be constructed even without a key (e.g. in tests
            // that only check service resolution, not actual API calls).
            $apiKey = config('content-translator.deepl.api_key') ?: 'placeholder';

            return new Translator($apiKey);
        });

        // Singletons — constructed once per container lifetime.
        $this->app->singleton(TranslationServiceFactory::class);
        $this->app->singleton(BardSerializer::class);
        $this->app->singleton(BardParser::class);
        $this->app->singleton(ContentExtractor::class);
        $this->app->singleton(ContentReassembler::class);

        // Bind the TranslationService contract to the configured implementation.
        // Tests can swap this binding via app()->instance(TranslationService::class, $mock).
        $this->app->singleton(TranslationService::class, function ($app) {
            return $app->make(TranslationServiceFactory::class)->make();
        });
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerConfiguration();
        $this->registerTranslations();
        $this->registerViews();
        $this->registerBlueprintInjection();
    }

    public function supportsInertia(): bool
    {
        return method_exists(Utility::class, 'inertia');
    }

    protected function bootVite(): static
    {
        $entryPoint = $this->supportsInertia()
            ? 'resources/js/v6/addon.ts'
            : 'resources/js/v5/addon.ts';

        $this->registerVite([
            'input' => [$entryPoint],
            'publicDirectory' => 'resources/dist',
        ]);

        return $this;
    }

    private function registerConfiguration(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/content-translator.php', 'content-translator');

        $this->publishes([
            __DIR__.'/../config/content-translator.php' => config_path('content-translator.php'),
        ], 'content-translator-config');
    }

    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'content-translator');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'content-translator');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/content-translator'),
        ], 'content-translator-views');
    }

    private function registerBlueprintInjection(): void
    {
        Event::listen(EntryBlueprintFound::class, function (EntryBlueprintFound $event): void {
            $entry = $event->entry;

            // Only inject when resolving a blueprint for an actual entry (not a
            // generic blueprint lookup without an entry context).
            if ($entry === null) {
                return;
            }

            $collectionHandle = $entry->collectionHandle();
            $configuredCollections = config('content-translator.collections', []);

            // Skip collections that are not configured.
            if (! in_array($collectionHandle, (array) $configuredCollections, true)) {
                return;
            }

            $blueprintHandle = $event->blueprint->handle();
            $excludedBlueprints = config('content-translator.exclude_blueprints', []);

            // Skip blueprints explicitly excluded in dot notation (collection.blueprint).
            $blueprintKey = $collectionHandle.'.'.$blueprintHandle;

            if (in_array($blueprintKey, (array) $excludedBlueprints, true)) {
                return;
            }

            $event->blueprint->ensureField('content_translator', [
                'type' => 'content_translator',
                'visibility' => 'computed',
                'localizable' => true,
                'display' => 'Content Translator',
                'listable' => 'hidden',
            ], 'sidebar');
        });
    }
}
