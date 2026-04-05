<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator;

use DeepL\Translator;
use ElSchneider\MagicTranslator\Contracts\TranslationService;
use ElSchneider\MagicTranslator\Extraction\BardSerializer;
use ElSchneider\MagicTranslator\Extraction\ContentExtractor;
use ElSchneider\MagicTranslator\Listeners\RefreshLocaleHashOnSave;
use ElSchneider\MagicTranslator\Reassembly\BardParser;
use ElSchneider\MagicTranslator\Reassembly\ContentReassembler;
use ElSchneider\MagicTranslator\Services\TranslationServiceFactory;
use ElSchneider\MagicTranslator\StatamicActions\TranslateEntryAction;
use ElSchneider\MagicTranslator\Support\BlueprintExclusions;
use ElSchneider\MagicTranslator\Support\SourceHashCache;
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
            $apiKey = config('statamic.magic-translator.deepl.api_key') ?: 'placeholder';

            return new Translator($apiKey);
        });

        // Singletons — constructed once per container lifetime.
        $this->app->singleton(TranslationServiceFactory::class);
        $this->app->singleton(BardSerializer::class);
        $this->app->singleton(BardParser::class);
        $this->app->singleton(ContentExtractor::class);
        $this->app->singleton(ContentReassembler::class);
        $this->app->singleton(SourceHashCache::class);

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
        $this->registerLocaleHashRefreshListener();
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
        $this->mergeConfigFrom(__DIR__.'/../config/magic-translator.php', 'statamic.magic-translator');

        $this->publishes([
            __DIR__.'/../config/magic-translator.php' => config_path('statamic/magic-translator.php'),
        ], 'statamic-magic-translator-config');
    }

    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'magic-translator');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'magic-translator');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/magic-translator'),
        ], 'statamic-magic-translator-views');
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

            $event->blueprint->ensureField('magic_translator', [
                'type' => 'magic_translator',
                'visibility' => 'computed',
                'localizable' => true,
                'display' => 'Magic Translator',
                'hide_display' => true,
                'listable' => 'hidden',
            ], 'sidebar');
        });
    }

    /**
     * Preserve `magic_translator` metadata on localized saves.
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

            if ($entry->get('magic_translator') !== null) {
                return;
            }

            $storedMeta = $entry->getOriginal('magic_translator');

            if ($storedMeta === null) {
                $storedMeta = Blink::get("magic-translator:meta:{$entry->id()}");
            }

            if ($storedMeta !== null) {
                $entry->set('magic_translator', $storedMeta);
            }
        });
    }

    private function registerLocaleHashRefreshListener(): void
    {
        Event::listen(EntrySaving::class, function (EntrySaving $event): void {
            app(RefreshLocaleHashOnSave::class)($event);
        });
    }
}
