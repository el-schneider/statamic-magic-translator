<?php

declare(strict_types=1);

namespace Tests;

use Statamic\Auth\User as UserModel;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

trait StatamicTestHelpers
{
    protected UserModel $testUser;

    protected function createTestUser(): UserModel
    {
        $this->testUser = User::make()
            ->email('test@example.com')
            ->id('test-user')
            ->set('name', 'Test User')
            ->set('super', true)
            ->password('password');

        $this->testUser->save();

        return $this->testUser;
    }

    protected function loginUser(?UserModel $user = null): UserModel
    {
        $user ??= $this->testUser ?? $this->createTestUser();

        $this->actingAs($user, 'statamic');

        return $user;
    }

    protected function createMultiSiteSetup(?array $sites = null): void
    {
        $sites ??= [
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
        ];

        Site::setSites($sites);
    }

    protected function createTestCollection(
        string $handle = 'articles',
        array $sites = ['en', 'fr']
    ): \Statamic\Entries\Collection {
        $collection = Collection::make($handle)
            ->title(ucfirst($handle))
            ->sites($sites);

        $collection->save();

        return $collection;
    }

    protected function createTestBlueprint(
        string $collection = 'articles',
        string $handle = 'default',
        array $fields = []
    ): \Statamic\Fields\Blueprint {
        $defaultFields = $fields ?: [
            [
                'handle' => 'title',
                'field' => [
                    'type' => 'text',
                    'localizable' => true,
                    'display' => 'Title',
                ],
            ],
            [
                'handle' => 'content',
                'field' => [
                    'type' => 'bard',
                    'localizable' => true,
                    'display' => 'Content',
                ],
            ],
            [
                'handle' => 'meta_description',
                'field' => [
                    'type' => 'textarea',
                    'localizable' => true,
                    'display' => 'Meta Description',
                ],
            ],
        ];

        $blueprint = Blueprint::makeFromTabs([
            'main' => [
                'display' => 'Main',
                'fields' => $defaultFields,
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$collection}");
        $blueprint->save();

        return $blueprint;
    }

    protected function createTestEntry(
        string $collection = 'articles',
        array $data = [],
        string $site = 'en',
        string $slug = 'test-entry'
    ): \Statamic\Entries\Entry {
        $defaultData = [
            'title' => 'Test Entry',
            'meta_description' => 'A test entry description',
        ];

        $entry = Entry::make()
            ->collection($collection)
            ->locale($site)
            ->slug($slug)
            ->data(array_merge($defaultData, $data));

        $entry->save();

        return $entry;
    }
}
