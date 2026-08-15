<?php

namespace App\Console\Commands;

use App\Metrics\MetricRegistry;
use Illuminate\Console\Command;

/**
 * Manual verification tool for the Semantic Metric Registry (spec Phase 1).
 * Useful in CI/local checks and when reviewing a catalog change — prints
 * exactly what MetricRegistry resolves, no separate implementation to drift
 * from the actual class.
 */
class ListMetrics extends Command
{
    protected $signature = 'metrics:list {--source=} {--json}';

    protected $description = 'List every metric in the Semantic Metric Registry';

    public function handle(MetricRegistry $registry): int
    {
        $source = $this->option('source');
        $definitions = $source ? $registry->forSource($source) : $registry->all();

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn ($d) => $d->toArray(), $definitions), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($definitions === []) {
            $this->warn($source ? "No metrics registered for source \"{$source}\"." : 'No metrics registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Ver', 'Label', 'Source', 'Unit', 'Table.Column', 'Derived'],
            array_map(fn ($d) => [
                $d->id,
                $d->version,
                $d->label,
                $d->source,
                $d->unit,
                $d->column ? "{$d->table}.{$d->column}" : "{$d->table} (derived)",
                $d->isDerived() ? 'yes: '.implode(', ', $d->dependsOn) : 'no',
            ], $definitions)
        );

        $this->info(count($definitions).' metric(s).');

        return self::SUCCESS;
    }
}
