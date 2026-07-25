<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Intelligence\AcquisitionIntelligence;
use App\Services\Intelligence\CustomerIntelligence;
use App\Services\Intelligence\ProductIntelligence;
use App\Services\Intelligence\SearchIntelligence;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * Advanced business intelligence (spec Phase 7).
 *
 * Each report is its own endpoint rather than one fat payload: they have very
 * different costs — cohorts walk every order the site has ever taken, channels
 * is one grouped read — and a dashboard that waits for the slowest of them
 * before showing anything is a dashboard people stop opening.
 *
 * Every report answers `has_data: false` with a machine-readable `reason`
 * instead of an empty shell. "Not enough customers to segment" and "no orders
 * at all" need different words on screen, and a caller cannot tell them apart
 * from a zeroed payload.
 */
class IntelligenceController extends Controller
{
    public function __construct(
        private CustomerIntelligence $customers,
        private ProductIntelligence $products,
        private SearchIntelligence $search,
        private AcquisitionIntelligence $acquisition,
    ) {}

    public function rfm(Request $request)
    {
        return response()->json($this->customers->rfm($this->site($request)));
    }

    public function cohorts(Request $request)
    {
        $months = (int) $request->integer('months', 12);

        return response()->json($this->customers->cohorts($this->site($request), $months));
    }

    public function lifetimeValue(Request $request)
    {
        return response()->json($this->customers->lifetimeValue($this->site($request)));
    }

    /**
     * Churn risk lists per-customer rows, so it is owner/admin only.
     *
     * A client_viewer is someone shown a site's results — usually the client
     * themselves or an outside stakeholder — and a list of identifiable
     * lapsing customers is not part of that. The route middleware enforces
     * this; the check is not repeated in the UI, because a hidden button is
     * not a permission.
     */
    public function churnRisk(Request $request)
    {
        return response()->json($this->customers->churnRisk($this->site($request)));
    }

    public function affinity(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->products->affinity($site, $this->range($request, $site)));
    }

    public function stockRisk(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->products->stockRisk($site, $this->range($request, $site)));
    }

    public function discountImpact(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->products->discountImpact($site, $this->range($request, $site)));
    }

    public function decliningProducts(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->products->decliningProducts($site, $this->range($request, $site)));
    }

    public function cannibalization(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->search->cannibalization($site, $this->range($request, $site)));
    }

    public function ctrOpportunities(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->search->ctrOpportunities($site, $this->range($request, $site)));
    }

    public function contentDecay(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->search->contentDecay($site, $this->range($request, $site)));
    }

    public function channels(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->acquisition->channels($site, $this->range($request, $site)));
    }

    public function anomalies(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->acquisition->trafficAnomalies($site, $this->range($request, $site)));
    }

    public function performanceCorrelation(Request $request)
    {
        $site = $this->site($request);

        return response()->json($this->acquisition->performanceRevenueCorrelation($site, $this->range($request, $site)));
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }

    private function range(Request $request, Site $site): DateRange
    {
        return DateRange::fromRequest(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
            $site->timezone ?: config('app.timezone', 'UTC'),
        );
    }
}
