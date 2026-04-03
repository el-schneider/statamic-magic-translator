<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator;

use DeepL\Translator;
use ElSchneider\ContentTranslator\Contracts\TranslationService;
use ElSchneider\ContentTranslator\Services\TranslationServiceFactory;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

final class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/v5/addon.ts',
            'resources/js/v6/addon.ts',
        ],
        'publicDirectory' => 'resources/dist',
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

        // Bind the TranslationService contract to the configured implementation.
        // Tests can swap this binding via app()->instance(TranslationService::class, $mock).
        $this->app->bind(TranslationService::class, function ($app) {
            return $app->make(TranslationServiceFactory::class)->make();
        });
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerConfiguration();
        $this->registerTranslations();
        $this->registerViews();
    }

    public function supportsInertia(): bool
    {
        return method_exists(Utility::class, 'inertia');
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
}
