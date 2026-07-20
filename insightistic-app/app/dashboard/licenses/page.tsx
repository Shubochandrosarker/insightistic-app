"use client";
import { useEffect, useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPost, apiDelete } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Spinner } from "@/components/ui/Spinner";
import { PageHeader } from "@/components/dashboard/PageHeader";
import {
  Copy, Check, KeyRound, RefreshCcw, Globe, Unplug, ShieldCheck, AlertTriangle, History,
} from "lucide-react";

type LicenseRow = {
  id: number;
  masked_key: string;
  last4: string;
  status: string;
  plan: { slug: string; name: string; site_limit: number } | null;
  activation_limit: number;
  activations_used: number;
  features: string[];
  created_at: string | null;
  last_validated_at: string | null;
};

type ActivationRow = {
  id: number;
  site_url: string;
  site_name: string | null;
  environment: string;
  status: string;
  wp_version: string | null;
  plugin_version: string | null;
  woocommerce_active: boolean;
  connection_status: string | null;
  last_sync_at: string | null;
  activated_at: string | null;
  last_seen_at: string | null;
};

type EventRow = { id: number; event_type: string; created_at: string };

const EVENT_LABEL: Record<string, string> = {
  activated: "Site activated",
  reactivated: "Site reconnected",
  deactivated: "Site deactivated",
  validation_ok: "License check-in",
  validation_denied: "License check-in denied",
  key_regenerated: "Key regenerated",
  license_suspended: "License suspended",
  license_reactivated: "License reactivated",
  license_revoked: "License revoked",
  license_disconnect_all: "All sites disconnected",
  activation_limit_overridden: "Activation limit changed",
  activation_revoked_by_admin: "Site disconnected by support",
};

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "violet" | "slate"> = {
  active: "green",
  trialing: "violet",
  payment_failed: "amber",
  expired: "amber",
  cancelled: "red",
  suspended: "red",
  revoked: "red",
};

const STATUS_LABEL: Record<string, string> = {
  active: "Active",
  trialing: "Trial",
  payment_failed: "Payment issue",
  expired: "Trial expired",
  cancelled: "Cancelled",
  suspended: "Suspended",
  revoked: "Revoked",
};

function CopyButton({ value, label = "Copy key" }: { value: string; label?: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <Button
      variant="soft"
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(value);
          setCopied(true);
          setTimeout(() => setCopied(false), 2000);
        } catch {
          /* clipboard unavailable (http) — user can select the text */
        }
      }}
    >
      {copied ? <Check size={15} /> : <Copy size={15} />} {copied ? "Copied" : label}
    </Button>
  );
}

/** Shown exactly once — right after signup or a regeneration. */
function OneTimeKeyCard({ rawKey, onDismiss }: { rawKey: string; onDismiss: () => void }) {
  return (
    <Card className="mb-5 border-brand/40 bg-gradient-to-br from-brand/[0.08] to-transparent">
      <div className="flex items-start gap-3">
        <div className="mt-0.5 rounded-xl bg-brand/15 p-2 text-brand-700"><KeyRound size={18} /></div>
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold text-fg">Your license key — shown only once</h2>
          <p className="mt-0.5 text-xs text-muted">
            Copy it now and paste it into your WordPress plugin. After you dismiss this card,
            only a masked version remains (you can always regenerate).
          </p>
          <div className="mt-3 overflow-x-auto rounded-xl border border-line bg-card2 px-4 py-3 font-mono text-sm text-fg">
            {rawKey}
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            <CopyButton value={rawKey} />
            <Button variant="ghost" onClick={onDismiss}>I saved my key</Button>
          </div>
        </div>
      </div>
    </Card>
  );
}

function ActivationMeter({ used, limit }: { used: number; limit: number }) {
  const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between text-sm">
        <span className="text-muted">Connected websites</span>
        <span className="font-semibold text-fg">{used} of {limit}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
        <div
          className="h-full rounded-full bg-gradient-to-r from-brand2 to-brand transition-all duration-700"
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}

const CONNECT_STEPS = [
  "Install the free Insightistic plugin on your WordPress site.",
  "Go to WordPress Admin → Insightistic → License.",
  "Paste your license key.",
  "Click Activate.",
  "Your site appears here automatically.",
];

export default function LicensesPage() {
  const { data, loading, reload } = useApi<{ licenses: LicenseRow[]; trial_ends_at: string | null; renewal_at: string | null }>("/licenses");
  const license = data?.licenses?.[0] ?? null;

  const { data: actData, reload: reloadActs } = useApi<{ activations: ActivationRow[] }>(
    license ? `/licenses/${license.id}/activations` : null,
    [license?.id],
  );

  const { data: eventsData } = useApi<{ events: EventRow[] }>(
    license ? `/licenses/${license.id}/events` : null,
    [license?.id],
  );

  const [oneTimeKey, setOneTimeKey] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [confirmRegen, setConfirmRegen] = useState(false);

  // Pick up the show-once key stashed by signup (cleared on dismiss).
  useEffect(() => {
    const k = sessionStorage.getItem("ins_license_key_once");
    if (k) setOneTimeKey(k);
  }, []);

  function dismissOneTime() {
    sessionStorage.removeItem("ins_license_key_once");
    setOneTimeKey(null);
  }

  async function regenerate() {
    if (!license) return;
    setBusy(true);
    setMsg(null);
    try {
      const r = await apiPost(`/licenses/${license.id}/regenerate`);
      sessionStorage.setItem("ins_license_key_once", r.license_key);
      setOneTimeKey(r.license_key);
      setConfirmRegen(false);
      reload();
    } catch (e: any) {
      setMsg(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function deactivate(id: number) {
    setBusy(true);
    setMsg(null);
    try {
      await apiDelete(`/activations/${id}`);
      reloadActs();
      reload();
    } catch (e: any) {
      setMsg(e.message);
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Spinner />;

  const activations = (actData?.activations ?? []).filter((a) => a.status === "active");
  const tone = license ? STATUS_TONE[license.status] ?? "slate" : "slate";

  return (
    <div>
      <PageHeader
        title="Licenses"
        subtitle="Use this key inside your WordPress plugin to unlock advanced Insightistic features."
      />

      {oneTimeKey && <OneTimeKeyCard rawKey={oneTimeKey} onDismiss={dismissOneTime} />}
      {msg && <p className="mb-4 rounded-lg border border-warn/30 bg-warn/10 px-3 py-2 text-sm text-warn">{msg}</p>}

      {!license ? (
        <Card>
          <p className="text-sm text-muted">No license found for this organization yet. Contact support.</p>
        </Card>
      ) : (
        <>
          <div className="grid gap-5 lg:grid-cols-2">
            {/* Key card */}
            <Card className="bg-gradient-to-br from-brand/[0.06] to-transparent">
              <div className="flex items-center justify-between gap-3">
                <div className="text-[11px] font-bold uppercase tracking-wide text-brand-700">License key</div>
                <Badge tone={tone} dot>{STATUS_LABEL[license.status] ?? license.status}</Badge>
              </div>
              <div className="mt-3 overflow-x-auto rounded-xl border border-line bg-card2 px-4 py-3 font-mono text-sm text-fg">
                insightistic_&hellip;{license.last4}
              </div>
              <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                <span>Plan: <strong className="text-fg">{license.plan?.name ?? "—"}</strong></span>
                {license.created_at && <span>Created {new Date(license.created_at).toLocaleDateString()}</span>}
                {data?.trial_ends_at && license.status === "trialing" && (
                  <span>Trial ends {new Date(data.trial_ends_at).toLocaleDateString()}</span>
                )}
                {data?.renewal_at && <span>Renews {new Date(data.renewal_at).toLocaleDateString()}</span>}
                {license.last_validated_at && (
                  <span>Last check-in {new Date(license.last_validated_at).toLocaleString()}</span>
                )}
              </div>

              <div className="mt-4 flex flex-wrap gap-2">
                {oneTimeKey && <CopyButton value={oneTimeKey} />}
                {!confirmRegen ? (
                  <Button variant="ghost" onClick={() => setConfirmRegen(true)} disabled={busy}>
                    <RefreshCcw size={15} /> Regenerate key
                  </Button>
                ) : (
                  <div className="flex flex-wrap items-center gap-2 rounded-xl border border-warn/30 bg-warn/10 px-3 py-2">
                    <AlertTriangle size={15} className="text-warn" />
                    <span className="text-xs text-warn">Old key stops working for new activations. Connected sites keep syncing.</span>
                    <Button variant="danger" onClick={regenerate} disabled={busy}>{busy ? "Working…" : "Confirm"}</Button>
                    <Button variant="ghost" onClick={() => setConfirmRegen(false)}>Keep current</Button>
                  </div>
                )}
              </div>
            </Card>

            {/* Usage + entitlements */}
            <Card title="Plan usage" subtitle="What this license unlocks right now.">
              <ActivationMeter used={license.activations_used} limit={license.activation_limit} />
              <div className="mt-4 flex flex-wrap gap-1.5">
                {license.features.map((f) => (
                  <Badge key={f} tone="green" className="capitalize">
                    <ShieldCheck size={12} /> {f.replace(/_/g, " ")}
                  </Badge>
                ))}
              </div>
              {license.activations_used >= license.activation_limit && (
                <div className="mt-4 rounded-xl border border-brand/25 bg-brand/[0.07] px-4 py-3">
                  <p className="text-sm text-fg">All website slots are in use.</p>
                  <a href="/dashboard/billing" className="mt-1 inline-block text-sm font-semibold text-brand hover:text-brand2">
                    Upgrade for more websites →
                  </a>
                </div>
              )}
            </Card>
          </div>

          {/* Connected sites */}
          <Card
            className="mt-5"
            title="Connected websites"
            subtitle="Sites that activated this license from the WordPress plugin."
          >
            {activations.length === 0 ? (
              <div className="rounded-xl border border-dashed border-line px-5 py-8 text-center">
                <Globe className="mx-auto mb-2 text-muted" size={22} />
                <p className="text-sm font-semibold text-fg">No websites connected yet</p>
                <p className="mx-auto mt-1 max-w-sm text-xs text-muted">
                  Connect your WordPress plugin to unlock your first performance snapshot.
                </p>
                <ol className="mx-auto mt-4 max-w-sm space-y-1.5 text-left text-xs text-muted">
                  {CONNECT_STEPS.map((s, i) => (
                    <li key={i} className="flex gap-2">
                      <span className="font-semibold text-brand-700">{i + 1}.</span> {s}
                    </li>
                  ))}
                </ol>
              </div>
            ) : (
              <div className="space-y-3">
                {activations.map((a) => (
                  <div
                    key={a.id}
                    className="flex flex-col gap-3 rounded-xl border border-line bg-card2 p-4 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate font-semibold text-fg">{a.site_name || a.site_url}</span>
                        {a.environment !== "production" && <Badge tone="slate">{a.environment}</Badge>}
                        {a.woocommerce_active && <Badge tone="violet">WooCommerce</Badge>}
                        <Badge tone={a.connection_status === "connected" ? "green" : "amber"} dot>
                          {a.connection_status ?? "pending"}
                        </Badge>
                      </div>
                      <div className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-muted">
                        <span>{a.site_url}</span>
                        {a.plugin_version && <span>Plugin v{a.plugin_version}</span>}
                        {a.wp_version && <span>WP {a.wp_version}</span>}
                        {a.last_sync_at && <span>Last sync {new Date(a.last_sync_at).toLocaleString()}</span>}
                      </div>
                    </div>
                    <Button variant="danger" onClick={() => deactivate(a.id)} disabled={busy}>
                      <Unplug size={15} /> Deactivate
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </Card>

          {/* Event history (spec §6.5) */}
          {eventsData && eventsData.events.length > 0 && (
            <Card className="mt-5" title="Recent activity">
              <ul className="space-y-2">
                {eventsData.events.map((e) => (
                  <li key={e.id} className="flex items-center justify-between gap-3 text-sm">
                    <span className="flex items-center gap-2 text-fg">
                      <History size={14} className="text-muted" />
                      {EVENT_LABEL[e.event_type] ?? e.event_type.replace(/_/g, " ")}
                    </span>
                    <span className="shrink-0 text-xs text-muted">{new Date(e.created_at).toLocaleString()}</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
