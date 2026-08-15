<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Metrics\MetricRegistry;
use App\Metrics\MetricSource;
use Illuminate\Http\Request;

/**
 * Read-only access to the Semantic Metric Registry (spec Phase 1). Returns
 * metric metadata only — ids, formulas, units, sources, caveats — never a
 * live value. Any authenticated tenant user can read the catalog: it is
 * product metadata, not tenant data, so no site scope is required.
 */
class MetricRegistryController extends Controller
{
    public function __construct(private readonly MetricRegistry $registry) {}

    public function index(Request $request)
    {
        $source = $request->query('source');

        if ($source !== null && ! in_array($source, MetricSource::ALL, true)) {
            return response()->json([
                'message' => 'Unknown source filter.',
                'allowed_sources' => MetricSource::ALL,
            ], 422);
        }

        $definitions = $source !== null ? $this->registry->forSource($source) : $this->registry->all();

        return response()->json([
            'metrics' => array_map(fn ($d) => $d->toArray(), $definitions),
            'sources' => MetricSource::ALL,
        ]);
    }

    public function show(string $metric)
    {
        if (! $this->registry->has($metric)) {
            return response()->json(['message' => "Unknown metric \"{$metric}\"."], 404);
        }

        return response()->json([
            'metric' => $this->registry->get($metric)->toArray(),
            'versions' => array_map(fn ($d) => $d->toArray(), $this->registry->versions($metric)),
        ]);
    }
}
