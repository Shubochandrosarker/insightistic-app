<?php

namespace App\Metrics;

/**
 * The Semantic Metric Registry. One versioned catalog of canonical metric
 * definitions that dashboards, Copilot, client reports and (future)
 * automations all resolve through, instead of each surface re-declaring
 * what "net_revenue" or "aov" means.
 *
 * This is a read-only, in-memory catalog built from App\Metrics\Catalog\*
 * on construction — definitions are code, reviewed like code, not editable
 * at runtime. That's a deliberate P0 choice: the typed semantic query
 * planner (P1) needs a fixed allowlist of metric ids/tables/columns to
 * validate an AI-authored plan against, and a catalog that only changes via
 * a reviewed commit is the safest allowlist to reason about.
 */
class MetricRegistry
{
    /** @var array<string, array<int, MetricDefinition>> metric id => version => definition */
    private array $definitions = [];

    /**
     * @param  MetricDefinition[]|null  $definitions  Inject an explicit list in tests; defaults to the full production catalog.
     */
    public function __construct(?array $definitions = null)
    {
        foreach ($definitions ?? MetricCatalog::all() as $definition) {
            $this->definitions[$definition->id][$definition->version] = $definition;
        }
    }

    /** Resolve a metric by id, optionally pinned to a specific version. Defaults to the latest version. */
    public function get(string $id, ?int $version = null): MetricDefinition
    {
        if (! isset($this->definitions[$id])) {
            throw MetricRegistryException::unknownMetric($id);
        }

        $version ??= $this->latestVersion($id);

        if (! isset($this->definitions[$id][$version])) {
            throw MetricRegistryException::unknownVersion($id, $version);
        }

        return $this->definitions[$id][$version];
    }

    public function has(string $id, ?int $version = null): bool
    {
        if (! isset($this->definitions[$id])) {
            return false;
        }

        return $version === null || isset($this->definitions[$id][$version]);
    }

    public function latestVersion(string $id): int
    {
        if (! isset($this->definitions[$id])) {
            throw MetricRegistryException::unknownMetric($id);
        }

        return max(array_keys($this->definitions[$id]));
    }

    /** All metric ids currently registered (latest version only), stable-sorted. */
    public function allIds(): array
    {
        $ids = array_keys($this->definitions);
        sort($ids);

        return $ids;
    }

    /** @return MetricDefinition[] Latest version of every registered metric. */
    public function all(): array
    {
        return array_map(fn (string $id) => $this->get($id), $this->allIds());
    }

    /** @return MetricDefinition[] Latest version of every registered metric for a given source (e.g. "woocommerce"). */
    public function forSource(string $source): array
    {
        return array_values(array_filter($this->all(), fn (MetricDefinition $d) => $d->source === $source));
    }

    /** Every historical version ever registered for a metric id, oldest first. Used by the evidence ledger to explain a value computed under an old definition. */
    public function versions(string $id): array
    {
        if (! isset($this->definitions[$id])) {
            throw MetricRegistryException::unknownMetric($id);
        }

        $versions = $this->definitions[$id];
        ksort($versions);

        return array_values($versions);
    }
}
