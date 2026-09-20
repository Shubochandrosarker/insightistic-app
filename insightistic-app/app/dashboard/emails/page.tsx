"use client";
import { useState } from "react";
import Link from "next/link";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost, apiDelete } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { timeAgo } from "@/lib/format";
import { Mail } from "lucide-react";

type Connection = {
  connected: boolean;
  provider: string | null;
  account: string | null;
  available_providers: { key: string; label: string }[];
  connector_providers: string[];
};

type Overview = {
  window_days: number;
  campaigns: number;
  providers: string[];
  totals: Record<string, number | null>;
  rates: Record<string, number | null>;
  currency: string | null;
};

type Campaign = {
  id: number;
  provider: string;
  name: string;
  type: string;
  status: string;
  sent_at: string | null;
  sent: number | null;
  delivered: number | null;
  opened: number | null;
  clicked: number | null;
  revenue: number | null;
  currency: string | null;
  rates: Record<string, number | null>;
};

const PROVIDER_LABEL: Record<string, string> = {
  mailchimp: "Mailchimp",
  klaviyo: "Klaviyo",
  fluentcrm: "FluentCRM",
  automatewoo: "AutomateWoo",
  woocommerce: "WooCommerce",
};

// A missing counter is not zero — the API sends null and we say so.
const num = (v: number | null | undefined) => (v === null || v === undefined ? "—" : v.toLocaleString());
const pct = (v: number | null | undefined) => (v === null || v === undefined ? "—" : `${v.toFixed(1)}%`);
const money = (v: number | null | undefined, currency: string | null) =>
  v === null || v === undefined ? "—" : `${currency ? currency + " " : ""}${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function EmailsPage() {
  const { siteId, sites, rangeQuery } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const [days, setDays] = useState(90);

  const overview = useApi<{ connection: Connection; overview: Overview }>(
    siteId ? `/sites/${siteId}/emails/overview?days=${days}${rangeQuery}` : null,
    [days, rangeQuery],
  );
  const list = useApi<{ campaigns: Campaign[] }>(siteId ? `/sites/${siteId}/emails/campaigns` : null);

  if (!siteId)
    return <EmptyState icon={<Mail size={20} />} title="No site connected" hint="Connect a store to see email performance." />;

  if (overview.loading) return <Spinner />;

  if (overview.error)
    return (
      <div>
        <PageHeader title="Emails" />
        <Card>
          <p className="text-sm text-bad">{overview.error}</p>
          <Button variant="ghost" className="mt-3" onClick={overview.reload}>Try again</Button>
        </Card>
      </div>
    );

  const connection = overview.data?.connection;
  const stats = overview.data?.overview;
  const campaigns = list.data?.campaigns || [];
  const currency = stats?.currency ?? null;

  const hasData = (stats?.campaigns ?? 0) > 0;

  const kpis = [
    { label: "Emails sent", value: num(stats?.totals.sent) },
    { label: "Open rate", value: pct(stats?.rates.open) },
    { label: "Click rate", value: pct(stats?.rates.click) },
    { label: "Revenue from email", value: money(stats?.totals.revenue, currency) },
  ];

  return (
    <div>
      <PageHeader
        title="Emails"
        subtitle={`Campaign performance and revenue from email for ${site?.name || "your store"}.`}
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        {[30, 90, 365].map((d) => (
          <button
            key={d}
            onClick={() => setDays(d)}
            className={`rounded-xl border px-3 py-1.5 text-xs font-semibold transition ${
              days === d ? "border-brand bg-brand/10 text-brand-700" : "border-line text-muted hover:border-brand/40"
            }`}
          >
            Last {d} days
          </button>
        ))}
        {stats?.providers.map((p) => (
          <Badge key={p} tone="violet">{PROVIDER_LABEL[p] ?? p}</Badge>
        ))}
      </div>

      <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
        {kpis.map((k) => (
          <div key={k.label} className="rounded-2xl border border-line bg-card p-4 shadow-card">
            <div className="text-xs font-medium text-muted">{k.label}</div>
            <div className="mt-1.5 text-[26px] font-bold text-fg">{k.value}</div>
          </div>
        ))}
      </div>

      <ConnectionCard connection={connection} siteId={siteId} onChanged={() => { overview.reload(); list.reload(); }} />

      <Card className="mt-5" title="Campaigns">
        {!hasData ? (
          <EmptyState
            icon={<Mail size={20} />}
            title="No campaign data yet"
            hint={
              connection?.connected
                ? "Your provider is connected. Run a sync, or wait for the next scheduled pull."
                : "Connect Mailchimp or Klaviyo above, or enable email sync in the Insightistic plugin for FluentCRM, AutomateWoo or WooCommerce mail."
            }
            action={
              !connection?.connected ? (
                <Link href="/dashboard/integrations" className="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand2">
                  Open integrations
                </Link>
              ) : undefined
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-xs text-muted">
                  <th className="pb-2 font-medium">Campaign</th>
                  <th className="pb-2 font-medium">Sent</th>
                  <th className="pb-2 font-medium">Delivered</th>
                  <th className="pb-2 font-medium">Opens</th>
                  <th className="pb-2 font-medium">Clicks</th>
                  <th className="pb-2 font-medium">Revenue</th>
                  <th className="pb-2 font-medium">When</th>
                </tr>
              </thead>
              <tbody>
                {campaigns.map((c) => (
                  <tr key={c.id} className="border-b border-line/60 last:border-0">
                    <td className="py-2.5 pr-3">
                      <div className="font-medium text-fg">{c.name}</div>
                      <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted">
                        <span>{PROVIDER_LABEL[c.provider] ?? c.provider}</span>
                        {c.type === "automation" && <Badge tone="slate">Automation</Badge>}
                      </div>
                    </td>
                    <td className="py-2.5 pr-3 text-fg">{num(c.sent)}</td>
                    <td className="py-2.5 pr-3 text-fg">{num(c.delivered)}</td>
                    <td className="py-2.5 pr-3 text-fg">
                      {num(c.opened)} <span className="text-xs text-muted">{pct(c.rates.open)}</span>
                    </td>
                    <td className="py-2.5 pr-3 text-fg">
                      {num(c.clicked)} <span className="text-xs text-muted">{pct(c.rates.click)}</span>
                    </td>
                    <td className="py-2.5 pr-3 text-fg">{money(c.revenue, c.currency ?? currency)}</td>
                    <td className="py-2.5 text-xs text-muted">{c.sent_at ? timeAgo(c.sent_at) : "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

function ConnectionCard({
  connection,
  siteId,
  onChanged,
}: {
  connection: Connection | undefined;
  siteId: number;
  onChanged: () => void;
}) {
  const [provider, setProvider] = useState("mailchimp");
  const [key, setKey] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [confirmOff, setConfirmOff] = useState(false);

  if (!connection) return null;

  async function run(name: string, fn: () => Promise<unknown>, after?: () => void) {
    setBusy(name);
    setErr(null);
    setNote(null);
    try {
      await fn();
      after?.();
    } catch (e: any) {
      setErr(e?.message || "That didn’t work. Try again.");
    } finally {
      setBusy(null);
    }
  }

  if (connection.connected)
    return (
      <Card className="mt-5" title="Email platform">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="font-medium text-fg">{PROVIDER_LABEL[connection.provider!] ?? connection.provider}</div>
            {connection.account && <div className="text-xs text-muted">Connected as {connection.account}</div>}
          </div>
          <Badge tone="green" dot>Connected</Badge>
        </div>

        {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}
        {note && <p className="mt-3 rounded-lg border border-good/25 bg-good/10 px-3 py-2 text-xs text-good">{note}</p>}

        <div className="mt-4 flex flex-wrap gap-2">
          <Button
            variant="ghost"
            disabled={busy === "sync"}
            onClick={() =>
              run("sync", async () => {
                const { result } = await apiPost(`/sites/${siteId}/emails/sync`);
                setNote(`Synced ${result.campaigns} campaign${result.campaigns === 1 ? "" : "s"}.`);
                onChanged();
              })
            }
          >
            {busy === "sync" ? "Syncing…" : "Sync now"}
          </Button>

          {confirmOff ? (
            <span className="inline-flex items-center gap-2">
              <Button
                variant="danger"
                disabled={busy === "disconnect"}
                onClick={() =>
                  run("disconnect", () => apiDelete(`/sites/${siteId}/emails/connection`), () => {
                    setConfirmOff(false);
                    onChanged();
                  })
                }
              >
                {busy === "disconnect" ? "Disconnecting…" : "Confirm disconnect"}
              </Button>
              <Button variant="ghost" onClick={() => setConfirmOff(false)}>Cancel</Button>
            </span>
          ) : (
            <Button variant="danger" onClick={() => setConfirmOff(true)}>Disconnect</Button>
          )}
        </div>
        <p className="mt-3 text-xs text-muted">
          Disconnecting stops future syncs. Campaigns already reported stay in your history.
        </p>
      </Card>
    );

  return (
    <Card className="mt-5" title="Connect an email platform">
      <p className="text-xs text-muted">
        Mailchimp and Klaviyo connect with an API key. FluentCRM, AutomateWoo and WooCommerce mail are
        reported by the Insightistic plugin instead — enable email sync there and campaigns appear here
        automatically.
      </p>

      <div className="mt-3 flex flex-wrap gap-2">
        {connection.available_providers.map((p) => (
          <button
            key={p.key}
            onClick={() => setProvider(p.key)}
            className={`rounded-xl border px-3 py-1.5 text-xs font-semibold transition ${
              provider === p.key ? "border-brand bg-brand/10 text-brand-700" : "border-line text-muted hover:border-brand/40"
            }`}
          >
            {p.label}
          </button>
        ))}
      </div>

      <div className="mt-3 max-w-md">
        <Field
          label={`${PROVIDER_LABEL[provider] ?? provider} API key`}
          value={key}
          onChange={(e) => setKey(e.target.value)}
          placeholder={provider === "mailchimp" ? "xxxxxxxxxxxx-us14" : "pk_xxxxxxxx"}
          hint={
            provider === "mailchimp"
              ? "Mailchimp → Account → Extras → API keys. Must include the data centre suffix."
              : "Klaviyo → Settings → API keys → Private API key with read access."
          }
        />
      </div>

      {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}

      <Button
        className="mt-3"
        disabled={key.length < 10 || busy === "connect"}
        onClick={() =>
          run("connect", () => apiPost(`/sites/${siteId}/emails/connect`, { provider, api_key: key }), () => {
            setKey("");
            onChanged();
          })
        }
      >
        {busy === "connect" ? "Verifying…" : "Connect"}
      </Button>
      <p className="mt-2 text-xs text-muted">Stored encrypted on the server and never shown again.</p>
    </Card>
  );
}
