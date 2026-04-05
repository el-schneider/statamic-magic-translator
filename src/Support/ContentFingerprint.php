<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Support;

use ElSchneider\MagicTranslator\Extraction\ContentExtractor;

final class ContentFingerprint
{
    public const HASH_VERSION = 'v1';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $fields
     */
    public static function compute(array $data, array $fields): string
    {
        /** @var ContentExtractor $extractor */
        $extractor = app(ContentExtractor::class);

        $units = $extractor->extract($data, $fields);

        $canonical = array_map(
            static fn ($unit): array => [
                'path' => $unit->path,
                'text' => $unit->text,
                'format' => $unit->format->value,
            ],
            $units,
        );

        usort($canonical, static function (array $a, array $b): int {
            return $a['path'] <=> $b['path'];
        });

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = '[]';
        }

        $digest = hash('sha256', $json);

        return self::HASH_VERSION.':sha256:'.$digest;
    }
}
