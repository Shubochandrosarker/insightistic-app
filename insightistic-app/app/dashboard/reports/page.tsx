"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/dashboard/DataTable";
import { Spinner } from "@/components/ui/Spinner";

export default function ReportsPage() {
  const { siteId } = useDashboard();
  const { data, loading, reload } = useApi<any>(siteId ? `/sites/${siteId}/reports` : null);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  async function generate(type: string) {
    if (!siteId) return;
    setBusy(type); setMsg(null);
    try { await apiPost(`/sites/${siteId}/reports/generate`, { type }); reload(); }
    catch (e: any) { setMsg(e.data?.message || e.message); }
    finally { setBusy(null); }
  }

  async function send(id: number) {
    const email = window.prompt("Send this report to which email?");
    if (!email) return;
    try { await apiPost(`/reports/${id}/send-email`, { email }); reload(); }
    catch (e: any) { setMsg(e.message); }
  }

  if (!siteId) return <p className="text-slate-400">Add a site first.</p>;

  const rows = (data?.reports || []).map((r: any) => [
    r.title || r.report_type,
    `${r.period_start ?? ""} → ${r.period_end ?? ""}`,
    <span key="l" className="inline-flex gap-3">
      {r.pdf_link && <a href={r.pdf_link} target="_blank" className="text-brand hover:underline">PDF</a>}
      {r.html_link && <a href={r.html_link} target="_blank" className="text-brand hover:underline">HTML</a>}
    </span>,
    r.sent_to ? `Sent → ${r.sent_to}` : <button key="s" onClick={() => send(r.id)} className="text-brand hover:underline">Send</button>,
  ]);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm text-slate-400">Generate:</span>
        {["weekly", "monthly"].map((t) => (
          <Button key={t} variant="ghost" disabled={!!busy} onClick={() => generate(t)}>
            {busy === t ? "Building…" : t}
          </Button>
        ))}
      </div>
      {msg && <p className="text-sm text-amber-300">{msg}</p>}
      <Card title="Reports">
        {loading ? <Spinner /> : <DataTable head={["Report", "Period", "Files", "Delivery"]} rows={rows} empty="No reports yet." />}
      </Card>
    </div>
  );
}
