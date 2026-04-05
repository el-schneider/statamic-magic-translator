<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Console;

enum PlanAction: string
{
    case Translate = 'translate';        // target missing — will create
    case Stale = 'stale';                // target exists but source newer — will re-translate
    case Overwrite = 'overwrite';        // target exists — will re-translate (nuclear flag)
    case SkipExists = 'skip_exists';     // target exists, no re-translate flag set
    case SkipUnsupported = 'skip_unsupported'; // entry's collection doesn't support target site

    public function willProcess(): bool
    {
        return match ($this) {
            self::Translate, self::Stale, self::Overwrite => true,
            self::SkipExists, self::SkipUnsupported => false,
        };
    }
}
