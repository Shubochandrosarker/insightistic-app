"use client";
import { useEffect, useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPatch, apiUpload } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { applyBrand } from "@/lib/dashboard";
import { Lock, Upload } from "lucide-react";

export default function BrandingPage() {
  const brand = useApi<any>("/brand-settings");
  const billing = useApi<any>("/billing/subscription");
  const [f, setF] = useState<any>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (brand.data?.brand_settings) setF(brand.data.brand_settings); }, [brand.data]);
  const up = (k: string) => (e: any) => setF({ ...f, [k]: e.target.value });

  const allowed = billing.data?.plan?.white_label_enabled ?? null; // null while loading
  const primary = f.primary_color || "#00C04B";

  async function save() {
    setBusy(true); setMsg(null);
    try {
      await apiPatch("/brand-settings", {
        primary_color: f.primary_color, accent_color: f.accent_color,
        email_from_name: f.email_from_name, email_from_address: f.email_from_address,
        report_footer_text: f.report_footer_text, custom_domain: f.custom_domain,
      });
      if (f.primary_color) applyBrand(f.primary_color); // apply live to the dashboard
      setMsg("Saved — your branding is now applied.");
      brand.reload();
    } catch (e: any) {
      setMsg(e.data?.code === "white_label_required" ? "White-label is an Agency-plan feature. Upgrade to customize branding." : e.message);
    } finally { setBusy(false); }
  }

  async function uploadLogo(e: any) {
    const file = e.target.files?.[0]; if (!file) return;
    const form = new FormData(); form.append("logo", file);
    setMsg(null);
    try { await apiUpload("/brand-settings/logo", form); setMsg("Logo updated."); brand.reload(); }
    catch (err: any) { setMsg(err.data?.code === "white_label_required" ? "White-label is an Agency-plan feature." : err.message); }
  }

  if (brand.loading) return <Spinner />;
  const locked = allowed === false;

  return (
    <div>
      <PageHeader title="White Label" subtitle="Brand the dashboard and reports your clients see." />

      {locked && (
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-violet/25 bg-gradient-to-r from-violet/[0.08] to-brand/[0.05] p-5">
          <div className="flex items-start gap-3">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet/15 text-violet"><Lock size={16} /></span>
            <div>
              <div className="text-sm font-semibold text-fg">White-label is an Agency feature</div>
              <div className="text-sm text-muted">Upgrade to Agency to add your logo, brand colors, custom domain, and branded client reports.</div>
            </div>
          </div>
          <a href="/dashboard/billing" className="rounded-xl bg-violet px-4 py-2 text-sm font-semibold text-white hover:opacity-90">Upgrade to Agency →</a>
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Brand settings">
          <div className="space-y-4">
            <div>
              <span className="mb-1.5 block text-xs font-semibold text-fg">Agency logo</span>
              <div className="flex items-center gap-3">
                {f.logo_url ? (
                  <img src={f.logo_url} alt="logo" className="h-11 w-11 rounded-xl border border-line object-contain" />
                ) : (
                  <span className="flex h-11 w-11 items-center justify-center rounded-xl text-sm font-bold text-white" style={{ background: primary }}>
                    {(f.email_from_name || "I")[0]?.toUpperCase()}
                  </span>
                )}
                <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-fg hover:bg-card2">
                  <Upload size={15} /> Upload logo
                  <input type="file" accept="image/*" onChange={uploadLogo} className="hidden" disabled={locked} />
                </label>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <span className="mb-1.5 block text-xs font-semibold text-fg">Primary color</span>
                <div className="flex items-center gap-2 rounded-xl border border-line bg-card2 px-2 py-1.5">
                  <input type="color" value={primary} onChange={up("primary_color")} disabled={locked} className="h-7 w-9 cursor-pointer rounded border-0 bg-transparent" />
                  <input value={f.primary_color || ""} onChange={up("primary_color")} placeholder="#00C04B" disabled={locked} className="w-full bg-transparent text-sm text-fg outline-none" />
                </div>
              </div>
              <div>
                <span className="mb-1.5 block text-xs font-semibold text-fg">Accent color</span>
                <div className="flex items-center gap-2 rounded-xl border border-line bg-card2 px-2 py-1.5">
                  <input type="color" value={f.accent_color || "#6C5CE7"} onChange={up("accent_color")} disabled={locked} className="h-7 w-9 cursor-pointer rounded border-0 bg-transparent" />
                  <input value={f.accent_color || ""} onChange={up("accent_color")} placeholder="#6C5CE7" disabled={locked} className="w-full bg-transparent text-sm text-fg outline-none" />
                </div>
              </div>
            </div>

            <Field label="Email sender name" value={f.email_from_name || ""} onChange={up("email_from_name")} disabled={locked} placeholder="Your Agency Insights" />
            <Field label="Report footer text" value={f.report_footer_text || ""} onChange={up("report_footer_text")} disabled={locked} placeholder="Prepared by Your Agency · hello@agency.com" />
            <Field label="Custom domain" value={f.custom_domain || ""} onChange={up("custom_domain")} disabled={locked} placeholder="reports.youragency.com" />
          </div>
          {msg && <p className="mt-3 text-sm text-good">{msg}</p>}
          <div className="mt-4"><Button onClick={save} disabled={busy || locked}>{busy ? "Saving…" : "Save brand settings"}</Button></div>
        </Card>

        {/* Live preview */}
        <Card title="Client dashboard preview" subtitle="How your branded client view looks">
          <div className="overflow-hidden rounded-2xl border border-line">
            <div className="flex items-center gap-2 px-4 py-3" style={{ background: primary }}>
              {f.logo_url ? <img src={f.logo_url} alt="" className="h-6 w-6 rounded object-contain bg-white/80 p-0.5" /> : <span className="h-2 w-2 rounded-full bg-white" />}
              <span className="text-sm font-bold text-white">{f.email_from_name || "Your Agency Insights"}</span>
              <span className="ml-auto text-[11px] text-white/80">Client view</span>
            </div>
            <div className="space-y-3 bg-card2 p-4">
              <div>
                <div className="text-xs text-muted">Revenue this month</div>
                <div className="text-2xl font-bold text-fg">$18,420 <span className="text-sm font-semibold" style={{ color: primary }}>+12%</span></div>
              </div>
              <div className="h-12 rounded-lg" style={{ background: `linear-gradient(180deg, ${primary}33, transparent)` }} />
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl border border-line bg-card p-3"><div className="text-[11px] text-muted">Orders</div><div className="text-lg font-bold text-fg">482</div></div>
                <div className="rounded-xl border border-line bg-card p-3"><div className="text-[11px] text-muted">New customers</div><div className="text-lg font-bold text-fg">214</div></div>
              </div>
            </div>
          </div>
          <p className="mt-3 text-xs text-muted">Saved branding is applied to your dashboard and to client-facing PDF reports.</p>
        </Card>
      </div>
    </div>
  );
}
