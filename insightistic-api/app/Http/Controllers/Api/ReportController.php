<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ClientReportMail;
use App\Models\ClientReport;
use App\Models\Site;
use App\Services\ReportBuilder;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ReportController extends Controller
{
    public function __construct(
        private ReportBuilder $builder,
        private UsageService $usage,
        private Tenancy $tenancy,
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

        $report = $this->builder->generate($site, $data['type']);
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
