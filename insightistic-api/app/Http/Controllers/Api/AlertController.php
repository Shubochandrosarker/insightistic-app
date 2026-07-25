<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Site;
use App\Services\AlertDetector;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function __construct(private AlertDetector $detector) {}

    /** GET /sites/{site}/alerts?status=open|resolved|all */
    public function index(Request $request)
    {
        $site = $this->site($request);
        $status = $request->query('status', 'open');

        $query = Alert::where('site_id', $site->id)->latest();
        if ($status === 'open') {
            $query->where('is_resolved', false);
        } elseif ($status === 'resolved') {
            $query->where('is_resolved', true);
        }

        return response()->json(['alerts' => $query->limit(100)->get()]);
    }

    /** POST /sites/{site}/alerts/scan — run the deterministic rules now instead of waiting for the schedule. */
    public function scan(Request $request)
    {
        $site = $this->site($request);
        $created = $this->detector->scan($site);

        return response()->json(['created' => count($created), 'alerts' => $created]);
    }

    /*
     * {site} must be declared before {alert}: route parameters are injected
     * positionally, so dropping it binds the *site* id to $alert. That only
     * misbehaves once a tenant has an alert whose id differs from its site's
     * — which is why it survived unnoticed.
     */

    /** POST /sites/{site}/alerts/{alert}/resolve */
    public function resolve(Request $request, int $siteId, int $alert)
    {
        $row = $this->find($request, $alert);
        $row->update(['is_resolved' => true]);

        return response()->json(['alert' => $row]);
    }

    /** POST /sites/{site}/alerts/{alert}/reopen */
    public function reopen(Request $request, int $siteId, int $alert)
    {
        $row = $this->find($request, $alert);
        $row->update(['is_resolved' => false]);

        return response()->json(['alert' => $row]);
    }

    private function find(Request $request, int $alertId): Alert
    {
        $site = $this->site($request);
        $row = Alert::where('site_id', $site->id)->find($alertId);

        abort_if(! $row, 404, 'Alert not found.');

        return $row;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
