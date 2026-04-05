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
use ElSchneider\ContentTranslator\Support\BlueprintExclusions;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Blink;
use Statamic\Providers\AddonServiceProvider;

final class ServiceProvider extends AddonServiceProvider
{
    protected $translations = false;

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
            $apiKey = config('statamic.content-translator.deepl.api_key') ?: 'placeholder';

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
        $this->registerEntrySavingListener();
    }

    public function supportsInertia(): bool
    {
        // Statamic v6 ships with Inertia.js; v5 does not.
        // We check for the Inertia class as a reliable v5/v6 discriminator
        // because method_exists() on a Facade class only returns true for
        // methods statically defined on the Facade itself, not the underlying class.
        return class_exists(\Inertia\Inertia::class);
    }

    protected function bootVite(): static
    {
        $entryPoint = $this->supportsInertia()
            ? 'resources/js/v6/addon.ts'
            : 'resources/js/v5/addon.ts';

        $input = [$entryPoint];

        if (! $this->supportsInertia()) {
            $input[] = 'resources/js/v5/addon.css';
        }

        $this->registerVite([
            'input' => $input,
            'publicDirectory' => 'resources/dist',
        ]);

        return $this;
    }

    private function registerConfiguration(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/content-translator.php', 'statamic.content-translator');

        $this->publishes([
            __DIR__.'/../config/content-translator.php' => config_path('statamic/content-translator.php'),
        ], 'statamic-content-translator-config');
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
        ], 'statamic-content-translator-views');
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
            $blueprintHandle = $event->blueprint->handle();

            if (BlueprintExclusions::contains($collectionHandle, $blueprintHandle)) {
                return;
            }

            $event->blueprint->ensureField('content_translator', [
                'type' => 'content_translator',
                'visibility' => 'computed',
                'localizable' => true,
                'display' => 'Content Translator',
                'hide_display' => true,
                'listable' => 'hidden',
            ], 'sidebar');
        });
    }

    /**
     * Preserve `content_translator` metadata on localized saves.
     */
    private function registerEntrySavingListener(): void
    {
        Event::listen(EntrySaving::class, function (EntrySaving $event): void {
            $entry = $event->entry;

            if (! $entry->hasOrigin()) {
                return;
            }

            $collectionHandle = $entry->collectionHandle();
            $blueprintHandle = $entry->blueprint()->handle();

            if (BlueprintExclusions::contains($collectionHandle, $blueprintHandle)) {
                return;
            }

            if ($entry->get('content_translator') !== null) {
                return;
            }

            $storedMeta = $entry->getOriginal('content_translator');

            if ($storedMeta === null) {
                $storedMeta = Blink::get("content-translator:meta:{$entry->id()}");
            }

            if ($storedMeta !== null) {
                $entry->set('content_translator', $storedMeta);
            }
        });
    }
}
