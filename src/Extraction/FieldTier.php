<?php

declare(strict_types=1);

namespace ElSchneider\MagicTranslator\Extraction;

enum FieldTier
{
    /** Flat text: text, textarea, markdown, link */
    case Tier1;

    /** Structural containers: replicator, grid, table */
    case Tier2;

    /** Bard (ProseMirror) fields */
    case Tier3;

    /** Skip: assets, toggles, dates, numbers, relations, and anything unknown */
    case Skip;
}
