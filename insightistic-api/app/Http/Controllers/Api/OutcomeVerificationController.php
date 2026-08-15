<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OutcomeVerification;
use App\Models\Site;
use App\Services\Outcome\OutcomeVerificationService;
use Illuminate\Http\Request;

/**
 * Read + manual "verify now" surface for Outcome Verification (spec: P11).
 * See docs/OUTCOME-VERIFICATION.md. Rows are created automatically by
 * `App\Services\Actions\ActionProposalService::approve()` — there is no
 * `store()` here, an outcome verification only ever exists because a real
 * approved-and-executed ActionProposal created it.
 */
class OutcomeVerificationController extends Controller
{
    public function __construct(private readonly OutcomeVerificationService $service) {}

    /** GET /sites/{site}/outcome-verifications?outcome= */
    public function index(Request $request)
    {
        $site = $this->site($request);
        $query = OutcomeVerification::query()->where('site_id', $site->id)->orderByDesc('created_at');

        if ($outcome = $request->query('outcome')) {
            $query->where('outcome', $outcome);
        }

        return response()->json(['outcome_verifications' => $query->limit(100)->get()]);
    }

    public function show(Request $request, int $siteId, int $outcomeVerification)
    {
        $row = $this->find($request, $outcomeVerification);

        return response()->json(['outcome_verification' => $row]);
    }

    /** POST .../verify — re-measures the watched metric now and classifies the outcome. A human may call this anytime; it does not wait for the usual 24h minimum window. */
    public function verify(Request $request, int $siteId, int $outcomeVerification)
    {
        $row = $this->find($request, $outcomeVerification);
        $site = $this->site($request);

        try {
            $verified = $this->service->verify($row, $site);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_verify'], 422);
        }

        return response()->json(['outcome_verification' => $verified]);
    }

    private function find(Request $request, int $id): OutcomeVerification
    {
        $site = $this->site($request);
        $row = OutcomeVerification::query()->where('site_id', $site->id)->find($id);

        abort_if(! $row, 404, 'Outcome verification not found.');

        return $row;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
