<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Models\Site;
use App\Services\UsageService;
use Illuminate\Http\Request;

/**
 * Scheduled report delivery (spec §12). CRUD only — actual sending is
 * ReportDeliveryController's sibling console command
 * (insightistic:reports-deliver, routes/console.php), which finds due
 * schedules and reuses the existing ReportBuilder/ClientReportMail that
 * already power on-demand reports, so scheduled and manual reports are
 * byte-for-byte the same branded output.
 */
class ReportScheduleController extends Controller
{
    public function __construct(private UsageService $usage) {}

    /** GET /sites/{site}/report-schedules */
    public function index(Request $request)
    {
        $site = $this->site($request);

        return response()->json(['schedules' => ReportSchedule::where('site_id', $site->id)->latest()->get()]);
    }

    /** POST /sites/{site}/report-schedules */
    public function store(Request $request)
    {
        $site = $this->site($request);
        $data = $this->validated($request);

        if (($data['is_active'] ?? true) && ! $this->canCreate($site->organization)) {
            return response()->json($this->usage->limitError(
                $site->organization,
                'scheduled_report_limit',
                $this->usage->scheduledReportsUsed($site->organization),
            ), 402);
        }

        $schedule = ReportSchedule::create(array_merge($data, ['site_id' => $site->id]));

        return response()->json(['schedule' => $schedule], 201);
    }

    /*
     * {site} must be declared before {schedule}: route parameters are injected
     * positionally, so dropping it binds the *site* id to $scheduleId and the
     * wrong schedule gets edited or deleted whenever the two ids differ.
     */

    /** PATCH /sites/{site}/report-schedules/{schedule} */
    public function update(Request $request, int $siteId, int $scheduleId)
    {
        $schedule = $this->find($request, $scheduleId);
        $data = $this->validated($request, partial: true);
        if (($data['is_active'] ?? $schedule->is_active) && ! $schedule->is_active && ! $this->canCreate($schedule->site->organization)) {
            return response()->json($this->usage->limitError(
                $schedule->site->organization,
                'scheduled_report_limit',
                $this->usage->scheduledReportsUsed($schedule->site->organization, $schedule->id),
            ), 402);
        }
        $schedule->update($data);

        return response()->json(['schedule' => $schedule->fresh()]);
    }

    /** DELETE /sites/{site}/report-schedules/{schedule} */
    public function destroy(Request $request, int $siteId, int $scheduleId)
    {
        $this->find($request, $scheduleId)->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'frequency'    => [$required, 'in:weekly,monthly'],
            'recipients'   => [$required, 'string', 'max:2000'],
            'send_day'     => ['nullable', 'string', 'max:20'],
            'send_time'    => ['nullable', 'string', 'max:10'],
            'timezone'     => ['nullable', 'string', 'max:64'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);
    }

    private function find(Request $request, int $scheduleId): ReportSchedule
    {
        $site = $this->site($request);
        $row = ReportSchedule::where('site_id', $site->id)->find($scheduleId);

        abort_if(! $row, 404, 'Schedule not found.');

        return $row;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }

    private function canCreate(\App\Models\Organization $organization): bool
    {
        return $this->usage->scheduledReportLimit($organization) > $this->usage->scheduledReportsUsed($organization);
    }
}
