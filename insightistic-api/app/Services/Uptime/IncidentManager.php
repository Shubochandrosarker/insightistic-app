<?php

namespace App\Services\Uptime;

use App\Models\Alert;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Services\Automation\AutomationDispatcher;
use Illuminate\Support\Carbon;

/**
 * The doc-16 incident lifecycle, applied to every probe result:
 *
 *   healthy -> suspected -> confirmed incident -> recovered
 *
 * SUSPECTED (started_at set, confirmed_at null): the FIRST failed probe
 * opens the incident but deliberately does NOT alert — "one failed probe
 * does not create false outage alert" is the acceptance criterion; a
 * transient 502 or a dropped packet must never page anyone.
 *
 * CONFIRMED: the SECOND consecutive failed probe that lands at least one
 * interval after started_at stamps confirmed_at (atomically, once) and
 * raises ONE alert, following ChangeDetection's direct-alert precedent:
 * this is a per-incident EVENT (no "condition clears" semantics that would
 * fit AlertDetector::scan's rule set), so the Alert row + the
 * AutomationDispatcher 'alert_created' hook are fired right here, keeping
 * Automation Studio flows origin-agnostic. Open-alert dedupe (AlertDetector
 * idiom) is the backstop; the atomic confirmed_at CAS is the real guard.
 *
 * RECOVERED: the first successful probe stamps recovered_at. Rows stay
 * forever — visible history is the other acceptance criterion. Recovery
 * also auto-resolves the open confirmation alert, mirroring
 * AlertDetector::clear(), so the NEXT incident can alert again.
 *
 * @return array{check: UptimeCheck, incident: UptimeIncident|null, event: string|null}
 *         event is one of incident_started | incident_confirmed |
 *         incident_recovered, or null when nothing notable happened.
 */
class IncidentManager
{
    public const EVENT_STARTED = 'incident_started';

    public const EVENT_CONFIRMED = 'incident_confirmed';

    public const EVENT_RECOVERED = 'incident_recovered';

    public const ALERT_TYPE = 'uptime_incident_confirmed';

    public function __construct(private readonly AutomationDispatcher $dispatcher) {}

    public function record(UptimeMonitor $monitor, array $result): array
    {
        $site = $monitor->site;

        if ($result['up']) {
            return $this->recordUp($monitor, $site, $result);
        }

        return $this->recordDown($monitor, $site, $result);
    }

    /** @param array{up: bool, status_code: int|null, response_ms: int|null, error: string|null} $result */
    private function recordUp(UptimeMonitor $monitor, Site $site, array $result): array
    {
        $check = $this->writeCheck($monitor, $result);
        $open = $monitor->openIncident();

        if ($open !== null) {
            $open->recovered_at = Carbon::now();
            $open->save();

            // The condition that raised the confirmation alert is no longer
            // true — auto-resolve it (AlertDetector::clear idiom) so a later
            // incident can raise a fresh alert instead of dedupe-hitting.
            Alert::where('site_id', $site->id)
                ->where('type', self::ALERT_TYPE)
                ->where('is_resolved', false)
                ->update(['is_resolved' => true]);

            return ['check' => $check, 'incident' => $open, 'event' => self::EVENT_RECOVERED];
        }

        return ['check' => $check, 'incident' => null, 'event' => null];
    }

    /** @param array{up: bool, status_code: int|null, response_ms: int|null, error: string|null} $result */
    private function recordDown(UptimeMonitor $monitor, Site $site, array $result): array
    {
        $check = $this->writeCheck($monitor, $result);
        $cause = $result['status_code'] !== null
            ? 'HTTP '.$result['status_code']
            : (string) ($result['error'] ?? 'probe failed');

        $open = $monitor->openIncident();

        if ($open === null) {
            // First failed probe: open the incident as SUSPECTED. No alert.
            $incident = UptimeIncident::create([
                'monitor_id'   => $monitor->id,
                'site_id'      => $monitor->site_id,
                'started_at'   => Carbon::now(),
                'cause'        => mb_substr($cause, 0, 255),
                'status_code'  => $result['status_code'],
            ]);

            return ['check' => $check, 'incident' => $incident, 'event' => self::EVENT_STARTED];
        }

        // Second (or later) consecutive failure, at least one interval after
        // the incident started: confirm ONCE via a compare-and-set so two
        // overlapping jobs can never both believe they confirmed it.
        $event = null;

        if ($open->confirmed_at === null
            && $open->started_at->lte(Carbon::now()->subMinutes($monitor->interval_minutes))) {
            $confirmed = UptimeIncident::query()
                ->whereKey($open->id)
                ->whereNull('confirmed_at')
                ->update([
                    'confirmed_at' => Carbon::now(),
                    'cause'        => mb_substr($cause, 0, 255),
                    'status_code'  => $result['status_code'],
                ]);

            if ($confirmed === 1) {
                $this->raiseConfirmedAlert($site, $result);
                $event = self::EVENT_CONFIRMED;
            }
        }

        return ['check' => $check, 'incident' => $open->fresh(), 'event' => $event];
    }

    private function writeCheck(UptimeMonitor $monitor, array $result): UptimeCheck
    {
        return UptimeCheck::create([
            'monitor_id'  => $monitor->id,
            'site_id'     => $monitor->site_id,
            'status'      => $result['up'] ? UptimeCheck::UP : UptimeCheck::DOWN,
            'status_code' => $result['status_code'],
            'response_ms' => $result['response_ms'],
            'error'       => $result['error'],
            'checked_at'  => Carbon::now(),
        ]);
    }

    /**
     * One alert per confirmation, in the ChangeDetection shape: Alert row +
     * AutomationDispatcher 'alert_created' hook (Automation Studio flows
     * must not distinguish alert origins), open-alert dedupe as backstop.
     *
     * @param array{up: bool, status_code: int|null, response_ms: int|null, error: string|null} $result
     */
    private function raiseConfirmedAlert(Site $site, array $result): ?Alert
    {
        $exists = Alert::query()
            ->where('site_id', $site->id)
            ->where('type', self::ALERT_TYPE)
            ->where('is_resolved', false)
            ->exists();

        if ($exists) {
            return null;
        }

        $detail = $result['status_code'] !== null
            ? sprintf('HTTP %d', $result['status_code'])
            : 'no response';

        $alert = Alert::query()->create([
            'site_id'     => $site->id,
            'type'        => self::ALERT_TYPE,
            'title'       => 'Site down',
            'message'     => sprintf('%s is down — confirmed after 2 probes (%s).', $site->name, $detail),
            'severity'    => 'high',
            'is_resolved' => false,
        ]);

        $this->dispatcher->dispatch('alert_created', $site, [
            'alert_type' => self::ALERT_TYPE,
            'alert_id'   => $alert->id,
        ]);

        return $alert;
    }
}
