<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator;

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

    public function boot(): void
    {
        parent::boot();

        $this->registerConfiguration();
        $this->registerTranslations();
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
}
