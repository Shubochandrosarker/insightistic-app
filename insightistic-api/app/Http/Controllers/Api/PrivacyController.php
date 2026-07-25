<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildOrganizationExport;
use App\Models\OrganizationExport;
use App\Services\AuditLogger;
use App\Services\Privacy\RetentionPurger;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Data export, deletion and retention for the current organization
 * (spec Phase 10).
 *
 * Everything here is owner-only and behind a fresh password confirmation:
 * these are the actions where a stolen-but-live session does the most
 * damage, and an export in particular is a portable copy of the whole
 * tenant.
 */
class PrivacyController extends Controller
{
    public function __construct(
        private readonly RetentionPurger $retention,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /privacy/overview */
    public function overview(Request $request)
    {
        $org = $this->org();
        $days = $this->retention->retentionDays($org);

        return response()->json([
            'retention' => [
                'days'      => $days,
                'unlimited' => $days === null || $days <= 0,
                // Named so the UI can explain what retention does and does
                // not touch, rather than implying orders get deleted.
                'applies_to' => 'Traffic, SEO, performance and engagement history',
                'excludes'   => 'Orders, customers, licenses and billing records',
            ],
            'ai' => [
                'external_processing_allowed' => (bool) $org->allow_external_ai,
            ],
            'deletion' => [
                'requested_at'  => $org->deletion_requested_at?->toIso8601String(),
                'scheduled_for' => $org->deletion_scheduled_for?->toIso8601String(),
                'grace_days'    => (int) config('insightistic.privacy.deletion_grace_days'),
            ],
            'exports' => OrganizationExport::where('organization_id', $org->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (OrganizationExport $e) => $e->toApiArray()),
        ]);
    }

    /** POST /privacy/exports */
    public function requestExport(Request $request)
    {
        $org = $this->org();

        // One build at a time: a second concurrent request would only produce
        // a duplicate archive of the same data.
        $pending = OrganizationExport::where('organization_id', $org->id)
            ->whereIn('status', ['queued', 'processing'])
            ->first();

        if ($pending) {
            return response()->json([
                'message' => 'An export is already being prepared.',
                'code'    => 'export_in_progress',
                'export'  => $pending->toApiArray(),
            ], 409);
        }

        $export = OrganizationExport::create([
            'organization_id'      => $org->id,
            'requested_by_user_id' => $request->user()->id,
            'status'               => 'queued',
        ]);

        BuildOrganizationExport::dispatch($export->id);

        $this->audit->log($request, 'privacy.export_requested', 'organization', $org->id);

        return response()->json(['export' => $export->toApiArray()], 202);
    }

    /** GET /privacy/exports/{export}/download */
    public function downloadExport(Request $request, int $exportId)
    {
        // Tenant-scoped by the global scope — another org's export is a 404.
        $export = OrganizationExport::findOrFail($exportId);

        if (! $export->isDownloadable()) {
            return response()->json([
                'message' => $export->status === 'ready'
                    ? 'This export has expired. Request a new one.'
                    : 'This export is not ready yet.',
                'code' => 'export_unavailable',
            ], 409);
        }

        $this->audit->log($request, 'privacy.export_downloaded', 'organization', $export->organization_id);

        return Storage::disk($export->disk)->download(
            $export->storage_path,
            "insightistic-export-{$export->organization_id}.zip",
        );
    }

    /** POST /privacy/deletion */
    public function requestDeletion(Request $request)
    {
        $org = $this->org();

        $request->validate([
            // Typing the organization name is the confirmation step — a
            // checkbox is too easy to click through for an irreversible act.
            'confirm_name' => ['required', 'string'],
        ]);

        if (trim((string) $request->input('confirm_name')) !== $org->name) {
            return response()->json([
                'message' => 'The name you typed does not match this organization.',
                'code'    => 'name_mismatch',
            ], 422);
        }

        $graceDays = (int) config('insightistic.privacy.deletion_grace_days');

        $org->forceFill([
            'deletion_requested_at'        => now(),
            'deletion_scheduled_for'       => now()->addDays($graceDays),
            'deletion_requested_by_user_id' => $request->user()->id,
        ])->save();

        $this->audit->log($request, 'privacy.deletion_requested', 'organization', $org->id, null, [
            'scheduled_for' => $org->deletion_scheduled_for->toIso8601String(),
        ]);

        return response()->json([
            'status'        => 'scheduled',
            'scheduled_for' => $org->deletion_scheduled_for->toIso8601String(),
            'grace_days'    => $graceDays,
        ]);
    }

    /** DELETE /privacy/deletion — cancel a pending deletion. */
    public function cancelDeletion(Request $request)
    {
        $org = $this->org();

        if (! $org->isPendingDeletion()) {
            return response()->json(['message' => 'No deletion is scheduled.', 'code' => 'not_scheduled'], 409);
        }

        $org->forceFill([
            'deletion_requested_at'        => null,
            'deletion_scheduled_for'       => null,
            'deletion_requested_by_user_id' => null,
        ])->save();

        $this->audit->log($request, 'privacy.deletion_cancelled', 'organization', $org->id);

        return response()->json(['status' => 'cancelled']);
    }

    /** PATCH /privacy/ai-processing */
    public function updateAiProcessing(Request $request)
    {
        $data = $request->validate(['allow_external_ai' => ['required', 'boolean']]);

        $org = $this->org();
        $org->forceFill(['allow_external_ai' => $data['allow_external_ai']])->save();

        $this->audit->log($request, 'privacy.ai_processing_updated', 'organization', $org->id, null, $data);

        return response()->json(['allow_external_ai' => (bool) $org->allow_external_ai]);
    }

    private function org()
    {
        return app(Tenancy::class)->organization();
    }
}
