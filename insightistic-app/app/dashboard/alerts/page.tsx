"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { timeAgo } from "@/lib/format";
import { AlertTriangle, Bell, RefreshCcw } from "lucide-react";

type Sev = "high" | "medium" | "low";
type AlertRow = { id: number; title: string; message: string | null; severity: Sev; is_resolved: boolean; created_at: string };

const SEV_TONE: Record<Sev, "red" | "amber" | "slate"> = { high: "red", medium: "amber", low: "slate" };

export default function AlertsPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const [showResolved, setShowResolved] = useState(false);
  const [scanning, setScanning] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [actionErr, setActionErr] = useState<string | null>(null);

  const { data, loading, error, reload } = useApi<{ alerts: AlertRow[] }>(
    siteId ? `/sites/${siteId}/alerts?status=${showResolved ? "resolved" : "open"}` : null,
    [showResolved],
  );

  if (!siteId) return <EmptyState icon={<Bell size={20} />} title="No site connected" hint="Connect a site to see alerts." />;

  async function scanNow() {
    if (!siteId) return;
    setScanning(true);
    setActionErr(null);
    try {
      await apiPost(`/sites/${siteId}/alerts/scan`);
      reload();
    } catch (e: any) {
      setActionErr(e?.message || "The scan couldn't be started.");
    } finally { setScanning(false); }
  }

  async function toggle(a: AlertRow) {
    setBusyId(a.id);
    setActionErr(null);
    try {
      await apiPost(`/sites/${siteId}/alerts/${a.id}/${a.is_resolved ? "reopen" : "resolve"}`);
      reload();
    } catch (e: any) {
      setActionErr(e?.message || "That change couldn't be saved.");
    } finally { setBusyId(null); }
  }

  const alerts = data?.alerts || [];

  return (
    <div>
      <PageHeader
        title="Alerts"
        subtitle={`Deterministic checks across ${site?.name || "your site"} — revenue, traffic, SEO, performance, stock, and sync health.`}
        right={
          <div className="flex items-center gap-2">
            <button
              onClick={() => setShowResolved((v) => !v)}
              className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                showResolved ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
              }`}
            >
              {showResolved ? "Showing resolved" : "Showing open"}
            </button>
            <Button variant="ghost" onClick={scanNow} disabled={scanning}>
              <RefreshCcw size={15} className={scanning ? "animate-spin" : ""} /> {scanning ? "Scanning…" : "Scan now"}
            </Button>
          </div>
        }
      />

      {actionErr && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">{actionErr}</p>
      )}

      {loading ? (
        <Spinner />
      ) : error ? (
        // A fetch failure must never read as "All clear" — that's a
        // monitoring page claiming everything is fine precisely when it
        // couldn't check.
        <ErrorState message={error} onRetry={reload} />
      ) : alerts.length === 0 ? (
        <EmptyState
          icon={<AlertTriangle size={20} />}
          title={showResolved ? "No resolved alerts" : "All clear"}
          hint={showResolved ? "Nothing has been resolved yet." : "No open alerts right now. Checks run automatically every 6 hours, or use Scan now."}
        />
      ) : (
        <div className="space-y-3">
          {alerts.map((a) => (
            <Card key={a.id}>
              <div className="flex items-center justify-between gap-4">
                <div className="flex items-start gap-3">
                  <span className={`mt-0.5 flex h-9 w-9 items-center justify-center rounded-xl ${a.severity === "high" ? "bg-bad/12 text-bad" : a.severity === "medium" ? "bg-warn/15 text-warn" : "bg-black/5 text-muted"}`}>
                    <AlertTriangle size={16} />
                  </span>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-fg">{a.title}</span>
                      <Badge tone={SEV_TONE[a.severity]}>{a.severity}</Badge>
                    </div>
                    {a.message && <div className="mt-0.5 text-sm text-muted">{a.message}</div>}
                    <div className="mt-0.5 text-xs text-muted">{timeAgo(a.created_at)}</div>
                  </div>
                </div>
                <Button variant="ghost" onClick={() => toggle(a)} disabled={busyId === a.id}>
                  {a.is_resolved ? "Reopen" : "Resolve"}
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
