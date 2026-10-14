<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Billing\AiCreditService;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Mail\ClientReportMail;
use App\Models\ClientReport;
use App\Models\Site;
use App\Services\ReportBuilder;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function __construct(
        private ReportBuilder $builder,
        private UsageService $usage,
        private Tenancy $tenancy,
        private AiCreditService $credits,
    ) {}

    /** GET /sites/{site}/reports — site.access middleware applied. */
    public function index(Request $request)
    {
        $site = $request->attributes->get('site');

        return response()->json([
            'reports' => ClientReport::query()
                ->where('site_id', $site->id)
                ->latest()
                ->limit(50)
                ->get(),
        ]);
    }

    /** POST /sites/{site}/reports/generate — site.access middleware applied. */
    public function generate(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        $org  = $this->tenancy->organization();

        $data = $request->validate([
            'type' => ['required', 'in:weekly,monthly'],
        ]);

        if (! $this->usage->canGenerateReport($org)) {
            return response()->json([
                'message' => 'Report limit reached for this month. Upgrade your plan for more.',
                'code'    => 'report_limit_reached',
            ], 402);
        }

        $creditModel = $this->credits->walletEnabled($org);
        $action = 'advanced_multi_source_ai_report';
        $includeAi = ! $creditModel || $this->credits->entitledForAction($org, $action);

        if ($creditModel && $includeAi && ! $this->credits->canCharge($org, $action)) {
            return response()->json([
                'message' => 'This report needs more Insight Credits. Buy a credit pack or upgrade your plan.',
                'code'    => 'insufficient_credits',
                'action'  => $action,
                'cost'    => $this->credits->cost($action),
                'available' => $this->credits->walletSummary($org)['total_usable'],
            ], 402);
        }

        $report = $this->builder->generate($site, $data['type'], $includeAi);

        if ($creditModel && $this->builder->aiSummaryGenerated()) {
            try {
                $operationId = $request->header('Idempotency-Key')
                    ?? $request->header('X-Insightistic-Operation-Id')
                    ?? ('report:' . $report->id);
                $this->credits->charge($org, $action, $operationId, false);
            } catch (InsufficientCreditsException $e) {
                $this->builder->discard($report);

                return response()->json([
                    'message' => 'This report needs more Insight Credits. Buy a credit pack or upgrade your plan.',
                    'code'    => 'insufficient_credits',
                    'action'  => $action,
                    'cost'    => $this->credits->cost($action),
                    'available' => $e->available,
                ], 402);
            }
        }

        $this->usage->recordReport($org);

        return response()->json([
            'report'    => $report,
            'remaining' => $this->usage->reportRemaining($org),
        ], 201);
    }

    /** GET /reports/{report} */
    public function show(Request $request, int $report)
    {
        $row = $this->findAccessible($request, $report);
        if (! $row) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        return response()->json(['report' => $row]);
    }

    /** POST /reports/{report}/send-email */
    public function sendEmail(Request $request, int $report)
    {
        $row = $this->findAccessible($request, $report);
        if (! $row) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        $data = $request->validate([
            'recipients'   => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
        ]);

        $org = $this->tenancy->organization();
        $brand = [
            'name'            => $org->name,
            'email_from_name' => $org->brandSettings->email_from_name ?? null,
            'primary_color'   => $org->brandSettings->primary_color ?? '#2563EB',
            'footer_text'     => $org->brandSettings->report_footer_text ?? null,
        ];

        Mail::to($data['recipients'])->send(new ClientReportMail($row, $brand));

        $row->update([
            'sent_to' => implode(', ', $data['recipients']),
            'sent_at' => now(),
        ]);

        return response()->json(['status' => 'sent', 'report' => $row]);
    }

    /**
     * GET /reports/{report}/pdf — signed, unauthenticated. `pdf_link` on the
     * model is a `URL::temporarySignedRoute()` to here, not a raw public-disk
     * URL: the browser opens it as a plain `<a href target=_blank>` (no
     * Authorization header on a top-level navigation), and streaming through
     * the app means it works regardless of whether the `public/storage`
     * symlink exists or `APP_URL` matches the disk's configured base URL —
     * only the URL Laravel itself just generated has to resolve, which it
     * always does by construction.
     */
    public function downloadPdf(Request $request, int $report)
    {
        return $this->streamReport($report, 'pdf_url', 'application/pdf');
    }

    /** GET /reports/{report}/html — signed, unauthenticated. See downloadPdf(). */
    public function downloadHtml(Request $request, int $report)
    {
        return $this->streamReport($report, 'html_url', 'text/html');
    }

    private function streamReport(int $reportId, string $column, string $contentType)
    {
        // No tenant/auth context on a signed link — the signature itself is
        // the access control, so this deliberately bypasses the org scope.
        $row = ClientReport::withoutGlobalScope('organization')->find($reportId);
        $path = $row?->{$column};
        $disk = config('insightistic.reports.disk', 'public');

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        return Storage::disk($disk)->response($path, null, ['Content-Type' => $contentType]);
    }

    /**
     * Resolve a report within the tenant, enforcing client_viewer site limits.
     * Tenant scope already restricts to the org; this adds the per-site gate.
     */
    private function findAccessible(Request $request, int $reportId): ?ClientReport
    {
        $report = ClientReport::find($reportId); // org-scoped by global scope
        if (! $report) {
            return null;
        }

        $user = $request->user();
        $role = $user->roleIn($report->organization);

        if ($role === 'client_viewer') {
            $granted = Site::where('id', $report->site_id)
                ->whereExists(function ($q) use ($user) {
                    $q->from('site_user_access')
                      ->whereColumn('site_user_access.site_id', 'sites.id')
                      ->where('site_user_access.user_id', $user->id);
                })
                ->exists();

            if (! $granted) {
                return null;
            }
        }

        return $report;
    }
}
