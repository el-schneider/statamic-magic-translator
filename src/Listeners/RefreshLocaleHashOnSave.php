<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Listeners;

use ElSchneider\MagicTranslator\Support\BlueprintExclusions;
use ElSchneider\MagicTranslator\Support\ContentFingerprint;
use ElSchneider\MagicTranslator\Support\FieldDefinitionBuilder;
use ElSchneider\MagicTranslator\Support\SourceHashCache;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Blink;

final class RefreshLocaleHashOnSave
{
    public function __construct(
        private readonly SourceHashCache $sourceHashCache,
    ) {}

    public function __invoke(EntrySaving $event): void
    {
        $entry = $event->entry;

        if ($entry->isRoot()) {
            return;
        }

        if (! $entry->isDirty()) {
            return;
        }

        $root = $entry->root() ?? $entry->origin();

        if ($root === null) {
            return;
        }

        if ($root->in($entry->locale()) === null) {
            return;
        }

        $collectionHandle = $entry->collectionHandle();
        $blueprintHandle = $entry->blueprint()->handle();

        if (BlueprintExclusions::contains($collectionHandle, $blueprintHandle)) {
            return;
        }

        if (Blink::has("magic-translator:translating:{$entry->id()}")) {
            return;
        }

        $fieldDefs = FieldDefinitionBuilder::fromBlueprint($entry->blueprint());
        $originalData = $this->getOriginalData($entry, array_keys($fieldDefs));
        $currentData = $entry->data()->all();

        $originalFingerprint = ContentFingerprint::compute($originalData, $fieldDefs);
        $currentFingerprint = ContentFingerprint::compute($currentData, $fieldDefs);

        if ($originalFingerprint === $currentFingerprint) {
            return;
        }

        $sourceFieldDefs = FieldDefinitionBuilder::fromBlueprint($root->blueprint());
        $sourceHash = $this->sourceHashCache->get($root, $sourceFieldDefs);

        $meta = $entry->get('magic_translator') ?? [];

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['last_translated_at'] = now()->toIso8601String();
        $meta['source_content_hash'] = $sourceHash;

        $entry->set('magic_translator', $meta);
    }

    /**
     * @param  array<int, string>  $fieldHandles
     * @return array<string, mixed>
     */
    private function getOriginalData($entry, array $fieldHandles): array
    {
        if (method_exists($entry, 'getRawOriginal')) {
            $rawData = $entry->getRawOriginal('data');

            if (is_array($rawData)) {
                return $rawData;
            }
        }

        $snapshot = [];

        foreach ($fieldHandles as $handle) {
            if (method_exists($entry, 'getRawOriginal')) {
                $snapshot[$handle] = $entry->getRawOriginal($handle);

                continue;
            }

            $snapshot[$handle] = $entry->getOriginal($handle);
        }

        return $snapshot;
    }
}
