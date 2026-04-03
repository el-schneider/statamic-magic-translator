<?php

declare(strict_types=1);

namespace Tests;

use Statamic\Auth\User as UserModel;
use Statamic\Facades\Blink;
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
        // Statamic blueprints use an ordered array of ['handle' => ..., 'field' => ...]
        // items in each section's `fields` list.
        //
        // If callers pass the older keyed-config format they used to, convert it:
        //   ['title' => ['type' => 'text', ...]]  →  [['handle' => 'title', 'field' => [...]]]
        // If they pass the ordered-item format, use it as-is.
        if ($fields) {
            // Detect keyed format: array keys are strings (field handles)
            $firstKey = array_key_first($fields);
            if (is_string($firstKey) && is_array($fields[$firstKey])) {
                $items = [];
                foreach ($fields as $h => $config) {
                    $items[] = ['handle' => $h, 'field' => $config];
                }
                $fieldItems = $items;
            } else {
                $fieldItems = $fields;
            }
        } else {
            $fieldItems = [
                ['handle' => 'title', 'field' => ['type' => 'text', 'localizable' => true, 'display' => 'Title']],
                ['handle' => 'content', 'field' => ['type' => 'bard', 'localizable' => true, 'display' => 'Content']],
                ['handle' => 'meta_description', 'field' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Meta Description']],
            ];
        }

        // Use setContents() directly with plain arrays so the blueprint serializes
        // correctly to YAML. makeFromTabs() stores Collections which become `null`
        // when YAML-serialized.
        $blueprint = Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => $fieldItems,
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$collection}");
        $blueprint->save();

        // Flush collection-level Blink caches so the saved blueprint is picked
        // up immediately when Entry::blueprint() is called in the same test.
        Blink::forget("collection-entry-blueprints-{$collection}");
        Blink::forget("collection-entry-blueprint-{$collection}-");
        Blink::forget("collection-entry-blueprint-{$collection}-{$handle}");

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
