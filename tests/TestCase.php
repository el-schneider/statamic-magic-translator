<?php

declare(strict_types=1);

namespace Tests;

use ElSchneider\ContentTranslator\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Facades\Site;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;
    use StatamicTestHelpers;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMultiSite();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.system.multisite', true);
    }

    protected function setUpMultiSite(): void
    {
        Site::setSites([
            'en' => [
                'name' => 'English',
                'url' => 'http://localhost/',
                'locale' => 'en',
            ],
            'fr' => [
                'name' => 'French',
                'url' => 'http://localhost/fr/',
                'locale' => 'fr',
            ],
        ]);
    }
}
