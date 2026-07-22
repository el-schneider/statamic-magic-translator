<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Support;

use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;

final class FieldDefinitionBuilder
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fromBlueprint(Blueprint $blueprint): array
    {
        return $blueprint->fields()->all()
            ->mapWithKeys(fn ($field) => [$field->handle() => self::normalizeFieldConfig($field->config())])
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function normalizeFieldConfig(array $config): array
    {
        $type = $config['type'] ?? 'text';

        return match ($type) {
            'replicator', 'bard' => self::normalizeSetConfig($config),
            'grid' => self::normalizeGridConfig($config),
            default => $config,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function normalizeSetConfig(array $config): array
    {
        $rawSets = $config['sets'] ?? [];

        if (empty($rawSets)) {
            return $config;
        }

        $firstItem = reset($rawSets);

        if (is_array($firstItem) && array_key_exists('sets', $firstItem)) {
            $flattened = [];

            foreach ($rawSets as $section) {
                foreach ($section['sets'] ?? [] as $setHandle => $setConfig) {
                    $flattened[(string) $setHandle] = $setConfig;
                }
            }

            $rawSets = $flattened;
        }

        $normalizedSets = [];

        foreach ($rawSets as $setHandle => $setConfig) {
            $normalizedSets[(string) $setHandle] = [
                'display' => $setConfig['display'] ?? $setHandle,
                'fields' => self::normalizeFieldItems($setConfig['fields'] ?? []),
            ];
        }

        $config['sets'] = $normalizedSets;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function normalizeGridConfig(array $config): array
    {
        $rawFields = $config['fields'] ?? [];

        if (empty($rawFields)) {
            return $config;
        }

        $config['fields'] = self::normalizeFieldItems($rawFields);

        return $config;
    }

    /**
     * @param  array<int|string, mixed>  $fieldItems
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeFieldItems(array $fieldItems): array
    {
        if (! isset($fieldItems[0]) && ! empty($fieldItems)) {
            $allStringKeys = array_reduce(
                array_keys($fieldItems),
                fn (bool $carry, mixed $key): bool => $carry && is_string($key),
                true,
            );

            if ($allStringKeys) {
                return array_map(
                    fn (array $fieldConfig) => self::normalizeFieldConfig($fieldConfig),
                    $fieldItems,
                );
            }
        }

        // Resolve the item list through Statamic's own Fields class so that
        // `import:` fieldsets (incl. prefixes) and `field: fieldset.handle`
        // references are expanded instead of dropped. Hand-parsing the raw YAML
        // silently skipped every set whose fields come from a fieldset import.
        $result = [];

        foreach ((new Fields($fieldItems))->all() as $handle => $field) {
            $config = $field->config();
            $config['type'] ??= $field->type();

            $result[(string) $handle] = self::normalizeFieldConfig($config);
        }

        return $result;
    }
}
