<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Site;
use App\Services\Anomaly\AnomalyScanner;
use Illuminate\Http\Request;

/** Read + manual "scan now" surface for the Anomaly Engine (spec: P7). Mirrors AlertController's shape exactly. */
class AnomalyController extends Controller
{
    public function __construct(private readonly AnomalyScanner $scanner) {}

    /** GET /sites/{site}/anomalies?status=open|acknowledged|all&metric_id= */
    public function index(Request $request)
    {
        $site = $this->site($request);
        $status = $request->query('status', 'open');

        $query = Anomaly::where('site_id', $site->id)->orderByDesc('date');
        if ($status === 'open') {
            $query->where('is_acknowledged', false);
        } elseif ($status === 'acknowledged') {
            $query->where('is_acknowledged', true);
        }
        if ($metricId = $request->query('metric_id')) {
            $query->where('metric_id', $metricId);
        }

        return response()->json(['anomalies' => $query->limit(200)->get()]);
    }

    /** POST /sites/{site}/anomalies/scan — run the detector now instead of waiting for the schedule. */
    public function scan(Request $request)
    {
        $site = $this->site($request);
        $created = $this->scanner->scan($site);

        return response()->json(['created' => count($created), 'anomalies' => $created]);
    }

    /** POST /sites/{site}/anomalies/{anomaly}/acknowledge */
    public function acknowledge(Request $request, int $siteId, int $anomaly)
    {
        $row = $this->find($request, $anomaly);
        $row->update(['is_acknowledged' => true]);

        return response()->json(['anomaly' => $row]);
    }

    private function find(Request $request, int $id): Anomaly
    {
        $site = $this->site($request);
        $row = Anomaly::where('site_id', $site->id)->find($id);

        abort_if(! $row, 404, 'Anomaly not found.');

        return $row;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
