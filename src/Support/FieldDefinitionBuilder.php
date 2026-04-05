<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Support;

use Statamic\Fields\Blueprint;

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

        $result = [];

        foreach ($fieldItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['import'])) {
                continue;
            }

            if (! isset($item['handle'])) {
                continue;
            }

            $handle = (string) $item['handle'];
            $fieldConfig = $item['field'] ?? [];

            if (is_string($fieldConfig)) {
                continue;
            }

            $result[$handle] = self::normalizeFieldConfig((array) $fieldConfig);
        }

        return $result;
    }
}
