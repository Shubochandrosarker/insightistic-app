"use client";
import { useEffect, useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPatch, apiUpload } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { Spinner } from "@/components/ui/Spinner";

export default function BrandingPage() {
  const { data, loading, reload } = useApi<any>("/brand-settings");
  const [f, setF] = useState<any>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (data?.brand_settings) setF(data.brand_settings); }, [data]);
  const up = (k: string) => (e: any) => setF({ ...f, [k]: e.target.value });

  async function save() {
    setBusy(true); setMsg(null);
    try {
      await apiPatch("/brand-settings", {
        primary_color: f.primary_color, accent_color: f.accent_color,
        email_from_name: f.email_from_name, email_from_address: f.email_from_address,
        report_footer_text: f.report_footer_text, custom_domain: f.custom_domain,
      });
      setMsg("Saved."); reload();
    } catch (e: any) { setMsg(e.data?.code === "white_label_required" ? "White-label is an Agency-plan feature. Upgrade to customize branding." : e.message); }
    finally { setBusy(false); }
  }

  async function uploadLogo(e: any) {
    const file = e.target.files?.[0]; if (!file) return;
    const form = new FormData(); form.append("logo", file);
    setMsg(null);
    try { await apiUpload("/brand-settings/logo", form); setMsg("Logo updated."); reload(); }
    catch (err: any) { setMsg(err.data?.code === "white_label_required" ? "White-label is an Agency-plan feature." : err.message); }
  }

  if (loading) return <Spinner />;

  return (
    <div className="max-w-xl space-y-5">
      <Card title="White-label branding">
        {msg && <p className="mb-3 text-sm text-amber-300">{msg}</p>}
        <div className="space-y-3">
          <Field label="Primary color (#hex)" value={f.primary_color || ""} onChange={up("primary_color")} placeholder="#2563EB" />
          <Field label="Accent color (#hex)" value={f.accent_color || ""} onChange={up("accent_color")} placeholder="#16A34A" />
          <Field label="Email from name" value={f.email_from_name || ""} onChange={up("email_from_name")} />
          <Field label="Email from address" value={f.email_from_address || ""} onChange={up("email_from_address")} />
          <Field label="Report footer text" value={f.report_footer_text || ""} onChange={up("report_footer_text")} />
          <Field label="Custom domain" value={f.custom_domain || ""} onChange={up("custom_domain")} placeholder="reports.youragency.com" />
        </div>
        <div className="mt-4"><Button onClick={save} disabled={busy}>{busy ? "Saving…" : "Save branding"}</Button></div>
      </Card>

      <Card title="Logo">
        {f.logo_url && <img src={f.logo_url} alt="logo" className="mb-3 max-h-16" />}
        <input type="file" accept="image/*" onChange={uploadLogo} className="text-sm text-slate-300" />
      </Card>
    </div>
  );
}
