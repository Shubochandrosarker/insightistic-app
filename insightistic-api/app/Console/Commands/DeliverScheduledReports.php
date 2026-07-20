<?php

namespace App\Console\Commands;

use App\Models\ClientReport;
use App\Models\ReportSchedule;
use App\Mail\ClientReportMail;
use App\Services\ReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Sends due scheduled reports (spec §12). Runs hourly (routes/console.php);
 * a schedule is "due" when the current local time (in its own timezone)
 * matches its send_day/send_time within this run's hour, AND no report of
 * that type has already been sent for this site within the current period
 * — checked against client_reports.sent_at rather than a new "last sent"
 * column, since that table already is the delivery log spec §12 asks for.
 *
 * Conventions (this table had no prior consumer to be compatible with):
 *   send_day  weekly  -> lowercase day name, e.g. "monday"
 *             monthly -> day-of-month "1".."28" (capped at 28 so it never
 *                        silently skips February)
 *   send_time "HH:MM" 24h, matched to the hour this command runs in.
 */
class DeliverScheduledReports extends Command
{
    protected $signature = 'insightistic:reports-deliver {--schedule= : Limit to one schedule id}';

    protected $description = 'Send scheduled weekly/monthly reports that are due right now.';

    public function handle(ReportBuilder $builder): int
    {
        $query = ReportSchedule::where('is_active', true)->with('site');
        if ($id = $this->option('schedule')) {
            $query->whereKey($id);
        }

        $sent = 0;
        foreach ($query->get() as $schedule) {
            if (! $schedule->site) {
                continue;
            }
            if (! $this->isDueNow($schedule)) {
                continue;
            }
            if ($this->alreadySentThisPeriod($schedule)) {
                continue;
            }

            $recipients = $schedule->recipientList();
            if (! $recipients) {
                $this->warn("Schedule {$schedule->id}: no recipients configured, skipping.");
                continue;
            }

            $report = $builder->generate($schedule->site, $schedule->frequency);
            $org = $schedule->site->organization;
            $brand = [
                'name'            => $org->name,
                'email_from_name' => $org->brandSettings->email_from_name ?? null,
                'primary_color'   => $org->brandSettings->primary_color ?? '#2563EB',
                'footer_text'     => $org->brandSettings->report_footer_text ?? null,
            ];

            Mail::to($recipients)->send(new ClientReportMail($report, $brand));
            $report->update(['sent_to' => implode(', ', $recipients), 'sent_at' => now()]);

            $sent++;
            $this->info("Schedule {$schedule->id} (site {$schedule->site_id}): sent {$schedule->frequency} report to " . count($recipients) . ' recipient(s).');
        }

        $this->info("Done — {$sent} report(s) sent.");

        return self::SUCCESS;
    }

    private function isDueNow(ReportSchedule $schedule): bool
    {
        $tz = $schedule->timezone ?: ($schedule->site->timezone ?: config('app.timezone', 'UTC'));
        $now = Carbon::now($tz);

        if (! $this->hourMatches($schedule->send_time, $now)) {
            return false;
        }

        return $schedule->frequency === 'monthly'
            ? $now->day === min(28, max(1, (int) ($schedule->send_day ?: 1)))
            : strtolower($now->format('l')) === strtolower((string) ($schedule->send_day ?: 'monday'));
    }

    private function hourMatches(?string $sendTime, Carbon $now): bool
    {
        if (! $sendTime || ! preg_match('/^(\d{1,2}):(\d{2})$/', $sendTime, $m)) {
            return $now->hour === 9; // sensible default if not configured
        }

        return $now->hour === (int) $m[1];
    }

    private function alreadySentThisPeriod(ReportSchedule $schedule): bool
    {
        $since = $schedule->frequency === 'monthly' ? now()->subDays(27) : now()->subDays(6);

        return ClientReport::where('site_id', $schedule->site_id)
            ->where('report_type', $schedule->frequency)
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since)
            ->exists();
    }
}
