<?php

declare(strict_types=1);

namespace ElSchneider\ContentTranslator\Commands;

use ElSchneider\ContentTranslator\Console\FilterCriteria;
use ElSchneider\ContentTranslator\Console\PlanAction;
use ElSchneider\ContentTranslator\Console\TranslationPlan;
use ElSchneider\ContentTranslator\Console\TranslationPlanner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Statamic\Console\RunsInPlease;

final class TranslateCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:content-translator:translate
                            {--to=*             : Target site handle (repeatable). Default: all sites each entry supports (minus source)}
                            {--from=            : Source site handle (default: entry origin)}
                            {--collection=*     : Filter by collection handle (repeatable)}
                            {--entry=*          : Filter by entry ID (repeatable)}
                            {--blueprint=*      : Filter by blueprint handle (repeatable)}
                            {--include-stale    : Also re-translate entries where source updated after target last_translated_at}
                            {--overwrite        : Re-translate everything regardless of existing state (nuclear option)}
                            {--generate-slug    : Slugify translated title}
                            {--dispatch-jobs    : Dispatch queue jobs instead of running synchronously}
                            {--dry-run          : Show the plan without executing}';

    protected $description = 'Translate Statamic entries across sites';

    public function handle(TranslationPlanner $planner): int
    {
        $criteria = $this->buildCriteria();

        if (! $criteria->hasAnySelectorFilter()) {
            $this->error('Refusing to run without at least one filter (--to, --collection, --entry, or --blueprint).');
            $this->line('Pass a filter to narrow the scope. Use --dry-run to preview the plan.');

            return 2;
        }

        try {
            $plan = $planner->plan($criteria);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $this->printPlan($plan, $criteria);

        if ($plan->processable() === []) {
            $this->info('No translations to perform.');

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes made.');

            return 0;
        }

        if (! $this->confirmExecution()) {
            if (! $this->option('no-interaction') && ! $this->input->isInteractive()) {
                return 2;
            }

            $this->info('Aborted.');

            return 0;
        }

        return 0;
    }

    private function buildCriteria(): FilterCriteria
    {
        return new FilterCriteria(
            targetSites: $this->normalizeArray($this->option('to')),
            sourceSite: $this->option('from') ?: null,
            collections: $this->normalizeArray($this->option('collection')),
            entryIds: $this->normalizeArray($this->option('entry')),
            blueprints: $this->normalizeArray($this->option('blueprint')),
            includeStale: (bool) $this->option('include-stale'),
            overwrite: (bool) $this->option('overwrite'),
        );
    }

    /**
     * @return string[]
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }

        $arr = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? mb_trim($v) : '', $arr),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function confirmExecution(): bool
    {
        if ($this->option('no-interaction')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to run non-interactively without -n / --no-interaction.');

            return false;
        }

        return $this->confirm('Proceed?', false);
    }

    private function printPlan(TranslationPlan $plan, FilterCriteria $criteria): void
    {
        $this->newLine();
        $this->line('<info>Content Translator — translation plan</info>');
        $this->line(str_repeat('─', 61));

        $filtersLine = sprintf(
            'Filters:       collection=%s, blueprint=%s, to=[%s]',
            $criteria->collections !== [] ? implode(',', $criteria->collections) : '*',
            $criteria->blueprints !== [] ? implode(',', $criteria->blueprints) : '*',
            $criteria->targetSites !== [] ? implode(',', $criteria->targetSites) : 'auto',
        );

        $this->line($filtersLine);
        $this->line('Source site:   '.($criteria->sourceSite ?? 'auto (entry origin)'));

        $mode = $criteria->overwrite
            ? 'overwrite (re-translate all)'
            : ($criteria->includeStale ? 'safe + stale' : 'safe (skip existing)');
        $this->line("Mode:          {$mode}");
        $this->newLine();

        $counts = $plan->countByAction();
        $this->line("Resolved:  {$plan->count()} candidate pairs");
        $this->newLine();
        $this->line('Breakdown:');
        $this->line(sprintf('  ✓ %3d  will translate          (target localization missing)', $counts[PlanAction::Translate->value] ?? 0));

        if ($criteria->includeStale) {
            $this->line(sprintf('  ↻ %3d  will re-translate       (stale)', $counts[PlanAction::Stale->value] ?? 0));
        }

        if ($criteria->overwrite) {
            $this->line(sprintf('  ↻ %3d  will overwrite          (--overwrite)', $counts[PlanAction::Overwrite->value] ?? 0));
        }

        $this->line(sprintf('  ⊘ %3d  skip — already exists   (pass --include-stale or --overwrite to process)', $counts[PlanAction::SkipExists->value] ?? 0));
        $this->line(sprintf('  ⚠ %3d  skip — unsupported site (entry\'s collection excludes target)', $counts[PlanAction::SkipUnsupported->value] ?? 0));
        $this->line(str_repeat('─', 61));

        $effective = count($plan->processable());
        $this->line("Effective work: {$effective} translations");
        $this->newLine();
    }
}
