<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Support;

use Illuminate\Support\Str;

final class BlueprintExclusions
{
    public static function contains(string $collectionHandle, string $blueprintHandle): bool
    {
        $blueprintKey = $collectionHandle.'.'.$blueprintHandle;
        $patterns = config('statamic.content-translator.exclude_blueprints', []);

        foreach ((array) $patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (Str::is($pattern, $blueprintKey)) {
                return true;
            }
        }

        return false;
    }
}
