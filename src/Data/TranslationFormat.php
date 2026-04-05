<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Data;

enum TranslationFormat: string
{
    case Plain = 'plain';
    case Html = 'html';
    case Markdown = 'markdown';
}
