"use client";
import { useEffect, useState } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiGet, apiPost, apiDelete } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { timeAgo } from "@/lib/format";
import { Plug } from "lucide-react";

const SOURCE_LABEL: Record<string, string> = {
  wordpress: "WordPress",
  woocommerce: "WooCommerce",
  ga4: "Google Analytics 4",
  gsc: "Google Search Console",
  pagespeed: "PageSpeed Insights",
  uptime: "Uptime monitoring",
  cloudflare: "Cloudflare",
  bing: "Bing Webmaster",
  engagement: "Engagement tracking",
};

// What the user is picking once the credential exists.
const RESOURCE_NOUN: Record<string, string> = {
  ga4: "property",
  gsc: "property",
  cloudflare: "zone",
  bing: "site",
};

const STATE_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  connected: "green",
  syncing: "green",
  degraded: "amber",
  error: "red",
  disabled: "slate",
  not_configured: "slate",
};

const STATE_LABEL: Record<string, string> = {
  connected: "Connected",
  syncing: "Syncing",
  degraded: "Needs attention",
  error: "Error",
  disabled: "Disabled",
  not_configured: "Not connected",
};

type Source = {
  source: string;
  state: string;
  connection_method: string | null;
  label: string | null;
  last_successful_at: string | null;
  latest_data_at: string | null;
  freshness: string;
  last_error_code: string | null;
  last_error_message: string | null;
  connectable: boolean;
  managed_by_platform?: boolean;
  auth_type: "oauth" | "api_token" | "api_key" | "plugin" | "built_in";
  configured: boolean;
  connected: boolean;
  detail: { connected_account: string | null; resource: { id: string } | null } | null;
  plugin_fed: { active: boolean; last_data_at: string | null } | null;
  attachable_from_org: boolean;
};

type GoogleAccount = {
  provider: string;
  account_email: string | null;
  account_id: string | null;
  scopes: string[];
  connected_at: string | null;
} | null;

type Resource = { id: string; name: string; account: string };

export default function IntegrationsPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const params = useSearchParams();
  const router = useRouter();

  const { data, loading, error, reload } = useApi<{ sources: Source[]; google_account?: GoogleAccount }>(
    siteId ? `/sites/${siteId}/integrations` : null,
  );

  // Result of the Google consent round-trip, handed back as a query string.
  const [banner, setBanner] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);

  useEffect(() => {
    const outcome = params.get("integration");
    const googleAccount = params.get("google_account");
    if (!outcome && !googleAccount) return;

    if (googleAccount) {
      setBanner({ tone: "ok", text: `Google account ${googleAccount} connected for your workspace.` });
    } else {
      setBanner(
        outcome === "connected"
          ? { tone: "ok", text: `Connected. Choose a ${RESOURCE_NOUN[params.get("source") || ""] || "property"} below to finish.` }
          : { tone: "bad", text: consentError(params.get("reason")) },
      );
    }

    // Drop the query string so a refresh doesn't replay the banner.
    router.replace("/dashboard/integrations");
  }, [params, router]);

  if (!siteId)
    return (
      <EmptyState
        icon={<Plug size={20} />}
        title="No website yet"
        hint="Add a website to see its data-source health here."
        action={<Button onClick={() => router.push("/dashboard/add-website")}>Add website</Button>}
      />
    );

  if (loading) return <Spinner />;

  if (error)
    return (
      <div>
        <PageHeader title="Integrations" />
        <Card>
          <p className="text-sm text-bad">{error}</p>
          <Button variant="ghost" className="mt-3" onClick={reload}>Try again</Button>
        </Card>
      </div>
    );

  const sources = data?.sources || [];
  const builtIn = sources.filter((s) => s.managed_by_platform);
  const direct = sources.filter((s) => s.connectable);
  const viaPlugin = sources.filter((s) => !s.connectable && !s.managed_by_platform);

  return (
    <div>
      <PageHeader
        title="Integrations"
        subtitle={`Connect analytics sources and check data-source health for ${site?.name || "this site"}.`}
      />

      {banner && (
        <div
          className={`mb-4 rounded-xl border px-4 py-3 text-sm ${
            banner.tone === "ok"
              ? "border-good/25 bg-good/10 text-good"
              : "border-bad/25 bg-bad/10 text-bad"
          }`}
        >
          {banner.text}
        </div>
      )}

      <GoogleAccountCard account={data?.google_account ?? null} onChanged={reload} />

      <h2 className="mb-3 mt-8 text-sm font-semibold text-fg">Built into Insightistic</h2>
      <p className="mb-3 text-xs text-muted">
        These checks run from the platform and do not require a WordPress plugin or external account.
      </p>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {builtIn.map((s) => <BuiltInSourceCard key={s.source} source={s} />)}
      </div>

      <h2 className="mb-3 mt-8 text-sm font-semibold text-fg">Connect accounts</h2>
      <div className="grid gap-4 lg:grid-cols-2">
        {direct.map((s) => (
          <DirectSourceCard key={s.source} source={s} siteId={siteId} onChanged={reload} />
        ))}
      </div>

      <h2 className="mb-3 mt-8 text-sm font-semibold text-fg">Platform connectors</h2>
      <p className="mb-3 text-xs text-muted">
        Optional connector — WordPress/WooCommerce data syncs through the plugin. A website never
        needs it for search, audits, uptime or PageSpeed.
      </p>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {viaPlugin.map((s) => (
          <Card key={s.source}>
            <div className="flex items-center justify-between gap-3">
              <span className="font-semibold text-fg">{SOURCE_LABEL[s.source] ?? s.source}</span>
              <Badge tone={STATE_TONE[s.state] ?? "slate"} dot>{STATE_LABEL[s.state] ?? s.state}</Badge>
            </div>
            {s.label && <p className="mt-1 text-xs text-muted">{s.label}</p>}
            <div className="mt-3 space-y-1 text-xs text-muted">
              <p>Last synced {s.last_successful_at ? timeAgo(s.last_successful_at) : "never"}</p>
              {s.latest_data_at && <p>Latest data {timeAgo(s.latest_data_at)}</p>}
            </div>
            {s.state === "error" && s.last_error_message && (
              <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{s.last_error_message}</p>
            )}
            {s.state === "not_configured" && (
              <p className="mt-3 text-xs text-muted">
                {s.source === "wordpress" || s.source === "woocommerce"
                  ? "Connects automatically once your license is activated."
                  : `Enable ${SOURCE_LABEL[s.source] ?? s.source} in the Insightistic plugin.`}
              </p>
            )}
          </Card>
        ))}
      </div>
    </div>
  );
}

function BuiltInSourceCard({ source }: { source: Source }) {
  return (
    <Card>
      <div className="flex items-center justify-between gap-3">
        <span className="font-semibold text-fg">{SOURCE_LABEL[source.source] ?? source.source}</span>
        <Badge tone={STATE_TONE[source.state] ?? "slate"} dot>{STATE_LABEL[source.state] ?? source.state}</Badge>
      </div>
      <p className="mt-1 text-xs text-muted">
        {source.source === "uptime" ? "HTTP availability and SSL checks." : "Mobile Lighthouse performance runs."}
      </p>
      <p className="mt-3 text-xs text-muted">
        Last check {source.latest_data_at ? timeAgo(source.latest_data_at) : "queued"}
      </p>
      {source.state === "error" && source.last_error_message && (
        <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{source.last_error_message}</p>
      )}
    </Card>
  );
}

/**
 * The ONE Google consent for the whole workspace (spec §5/§6). When this is
 * connected, GSC and GA4 attach to any site with no second consent — and
 * the Add Website page can import every Search Console property.
 */
function GoogleAccountCard({ account, onChanged }: { account: GoogleAccount; onChanged: () => void }) {
  const [busy, setBusy] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [confirmOff, setConfirmOff] = useState(false);

  async function run(key: string, fn: () => Promise<void>) {
    setBusy(key);
    setErr(null);
    try {
      await fn();
    } catch (e: any) {
      setErr(e?.message || "That didn't work. Try again.");
    } finally {
      setBusy(null);
    }
  }

  const connect = () =>
    run("connect", async () => {
      const { authorize_url } = await apiPost("/integrations/google/account/authorize-url");
      window.location.href = authorize_url;
    });

  const disconnect = () =>
    run("disconnect", async () => {
      await apiDelete("/integrations/google/account");
      setConfirmOff(false);
      onChanged();
    });

  return (
    <Card className="mb-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <span className="font-semibold text-fg">Google account</span>
          <p className="mt-0.5 text-xs text-muted">
            One connection for your whole workspace — Search Console and Google Analytics,
            read-only, shared by every website.
          </p>
        </div>
        {account ? (
          <div className="flex items-center gap-3">
            <Badge tone="green" dot>Connected</Badge>
            {confirmOff ? (
              <span className="inline-flex items-center gap-2">
                <Button variant="danger" disabled={busy === "disconnect"} onClick={disconnect}>
                  {busy === "disconnect" ? "Disconnecting…" : "Confirm disconnect"}
                </Button>
                <Button variant="ghost" onClick={() => setConfirmOff(false)}>Cancel</Button>
              </span>
            ) : (
              <Button variant="danger" onClick={() => setConfirmOff(true)}>Disconnect</Button>
            )}
          </div>
        ) : (
          <Button disabled={busy === "connect"} onClick={connect}>
            {busy === "connect" ? "Redirecting…" : "Connect Google"}
          </Button>
        )}
      </div>
      {account && (
        <p className="mt-2 text-xs text-muted">
          Connected as <span className="font-medium text-fg">{account.account_email ?? "Google account"}</span>
          {account.connected_at ? ` · since ${timeAgo(account.connected_at)}` : ""} · GSC and GA4
          attach to sites below without another consent.
        </p>
      )}
      {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}
    </Card>
  );
}

function DirectSourceCard({ source: s, siteId, onChanged }: { source: Source; siteId: number; onChanged: () => void }) {
  const [busy, setBusy] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [token, setToken] = useState("");
  const [apiKey, setApiKey] = useState("");
  const [resources, setResources] = useState<Resource[] | null>(null);
  const [confirmOff, setConfirmOff] = useState(false);

  const noun = RESOURCE_NOUN[s.source] || "property";

  // Every action shares this wrapper so a failure always surfaces the API's
  // own message rather than a generic "something went wrong".
  async function run(key: string, fn: () => Promise<unknown>, after?: () => void) {
    setBusy(key);
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

  const connect = () =>
    run("connect", async () => {
      const { authorize_url } = await apiPost(`/sites/${siteId}/integrations/${s.source}/authorize-url`);
      window.location.href = authorize_url;
    });

  // Attach gsc/ga4 from the workspace's shared Google grant — no consent.
  const attachFromOrg = () =>
    run(
      "attach",
      () => apiPost(`/sites/${siteId}/integrations/${s.source}/attach`),
      () => {
        setNote("Attached from your Google account — choose the property below.");
        onChanged();
      },
    );

  const connectCloudflare = () =>
    run(
      "connect",
      () => apiPost(`/sites/${siteId}/integrations/cloudflare/connect`, { api_token: token }),
      () => {
        setToken("");
        onChanged();
      },
    );

  const connectBing = () =>
    run(
      "connect",
      () => apiPost(`/sites/${siteId}/integrations/bing/connect`, { api_key: apiKey }),
      () => {
        setApiKey("");
        onChanged();
      },
    );

  const loadResources = () =>
    run("resources", async () => {
      const { resources } = await apiGet(`/sites/${siteId}/integrations/${s.source}/resources`);
      setResources(resources);
    });

  const select = (r: Resource) =>
    run(
      "select",
      () => apiPost(`/sites/${siteId}/integrations/${s.source}/select`, { id: r.id, name: r.name }),
      () => {
        setResources(null);
        onChanged();
      },
    );

  const test = () =>
    run("test", async () => {
      await apiPost(`/sites/${siteId}/integrations/${s.source}/test`);
      setNote("Connection works.");
      onChanged();
    });

  const resync = () =>
    run("resync", async () => {
      await apiPost(`/sites/${siteId}/integrations/${s.source}/resync`);
      setNote("Sync queued — data will appear shortly.");
      onChanged();
    });

  const disconnect = () =>
    run(
      "disconnect",
      () => apiDelete(`/sites/${siteId}/integrations/${s.source}`),
      () => {
        setConfirmOff(false);
        setResources(null);
        onChanged();
      },
    );

  return (
    <Card>
      <div className="flex items-center justify-between gap-3">
        <span className="font-semibold text-fg">{SOURCE_LABEL[s.source] ?? s.source}</span>
        <Badge tone={STATE_TONE[s.state] ?? "slate"} dot>{STATE_LABEL[s.state] ?? s.state}</Badge>
      </div>

      {s.detail?.connected_account && (
        <p className="mt-1 text-xs text-muted">Connected as {s.detail.connected_account}</p>
      )}
      {s.label && <p className="mt-1 text-xs text-muted">{noun[0].toUpperCase() + noun.slice(1)}: {s.label}</p>}

      <div className="mt-3 space-y-1 text-xs text-muted">
        <p>Last synced {s.last_successful_at ? timeAgo(s.last_successful_at) : "never"}</p>
        {s.latest_data_at && (
          <p>
            Latest data {timeAgo(s.latest_data_at)}
            {s.freshness === "stale" && <span className="ml-1 text-warn">(stale)</span>}
          </p>
        )}
      </div>

      {!s.configured && (
        <p className="mt-3 rounded-lg border border-warn/25 bg-warn/10 px-3 py-2 text-xs text-warn">
          Google integration isn’t configured on this server yet. An administrator needs to set
          GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.
        </p>
      )}

      {s.last_error_message && s.last_error_code !== "no_property_selected" && (
        <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{s.last_error_message}</p>
      )}
      {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}
      {note && <p className="mt-3 rounded-lg border border-good/25 bg-good/10 px-3 py-2 text-xs text-good">{note}</p>}

      {/* Plugin-fed line (spec §12): data arriving via the plugin is shown
          as such, next to a direct connect path — never a flat "Not
          connected" while data is flowing. */}
      {s.plugin_fed?.active && !s.connected && (
        <p className="mt-3 rounded-lg border border-brand/25 bg-brand/10 px-3 py-2 text-xs text-fg">
          Connected via the WordPress plugin
          {s.plugin_fed.last_data_at ? ` — last data ${timeAgo(s.plugin_fed.last_data_at)}` : ""}.
          Connect directly with Google for SaaS-side syncs.
        </p>
      )}

      {/* Step 1 — no credential yet. */}
      {s.state === "not_configured" && s.auth_type === "oauth" && (
        <div className="mt-4 flex flex-wrap gap-2">
          {s.attachable_from_org && (
            <Button disabled={busy === "attach"} onClick={attachFromOrg}>
              {busy === "attach" ? "Attaching…" : "Attach from Google account"}
            </Button>
          )}
          <Button variant={s.attachable_from_org ? "ghost" : "primary"} disabled={!s.configured || busy === "connect"} onClick={connect}>
            {busy === "connect" ? "Redirecting…" : s.attachable_from_org ? "Connect a different Google account" : "Connect with Google"}
          </Button>
        </div>
      )}

      {s.state === "not_configured" && s.auth_type === "api_token" && (
        <div className="mt-4 space-y-2">
          <Field
            label="Cloudflare API token"
            value={token}
            onChange={(e) => setToken(e.target.value)}
            placeholder="Token with Zone → Analytics → Read"
            hint="Created in Cloudflare under My Profile → API Tokens. Stored encrypted; never shown again."
          />
          <Button disabled={token.length < 20 || busy === "connect"} onClick={connectCloudflare}>
            {busy === "connect" ? "Verifying…" : "Connect Cloudflare"}
          </Button>
        </div>
      )}

      {/* Bing Webmaster: pasted API key, same flow shape as Cloudflare (min:16 server-side). */}
      {s.state === "not_configured" && s.auth_type === "api_key" && (
        <div className="mt-4 space-y-2">
          <Field
            label="Bing Webmaster API key"
            type="password"
            value={apiKey}
            onChange={(e) => setApiKey(e.target.value)}
            placeholder="API key from Bing Webmaster Tools"
            hint="Created in Bing Webmaster Tools under Settings → API Access. Stored encrypted; never shown again."
          />
          <Button disabled={apiKey.length < 16 || busy === "connect"} onClick={connectBing}>
            {busy === "connect" ? "Verifying…" : "Connect Bing Webmaster"}
          </Button>
        </div>
      )}

      {/* Step 2 — credential exists, resource still needs picking. */}
      {s.state !== "not_configured" && !s.connected && (
        <div className="mt-4">
          <p className="mb-2 text-xs text-muted">Choose which {noun} this site should report on.</p>
          {resources === null ? (
            <Button variant="ghost" disabled={busy === "resources"} onClick={loadResources}>
              {busy === "resources" ? "Loading…" : `Choose ${noun}`}
            </Button>
          ) : resources.length === 0 ? (
            <p className="text-xs text-muted">
              No {noun}s are available to the connected account. Check its permissions, then reconnect.
            </p>
          ) : (
            <ul className="space-y-1.5">
              {resources.map((r) => (
                <li key={r.id}>
                  <button
                    className="w-full rounded-xl border border-line px-3 py-2 text-left text-sm text-fg transition hover:border-brand/40 hover:bg-card2 disabled:opacity-50"
                    disabled={busy === "select"}
                    onClick={() => select(r)}
                  >
                    <span className="font-medium">{r.name}</span>
                    {r.account && <span className="ml-2 text-xs text-muted">{r.account}</span>}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {/* Step 3 — fully connected. */}
      <div className="mt-4 flex flex-wrap gap-2">
        {s.connected && (
          <>
            <Button variant="ghost" disabled={busy === "test"} onClick={test}>
              {busy === "test" ? "Testing…" : "Test connection"}
            </Button>
            <Button variant="ghost" disabled={busy === "resync"} onClick={resync}>
              {busy === "resync" ? "Queuing…" : "Sync now"}
            </Button>
            <Button variant="ghost" disabled={busy === "resources"} onClick={loadResources}>
              Change {noun}
            </Button>
          </>
        )}

        {s.state !== "not_configured" &&
          (confirmOff ? (
            <span className="inline-flex items-center gap-2">
              <Button variant="danger" disabled={busy === "disconnect"} onClick={disconnect}>
                {busy === "disconnect" ? "Disconnecting…" : "Confirm disconnect"}
              </Button>
              <Button variant="ghost" onClick={() => setConfirmOff(false)}>Cancel</Button>
            </span>
          ) : (
            <Button variant="danger" onClick={() => setConfirmOff(true)}>Disconnect</Button>
          ))}
      </div>
    </Card>
  );
}

function consentError(reason: string | null): string {
  switch (reason) {
    case "access_denied":
      return "You declined the permission request, so nothing was connected.";
    case "invalid_state":
      return "That sign-in link expired. Start the connection again.";
    case "forbidden":
      return "You no longer have access to this site’s organization.";
    case "missing_refresh_token":
      return "Google didn’t return offline access. Remove Insightistic from your Google account permissions, then connect again.";
    default:
      return "Google couldn’t complete the connection. Please try again.";
  }
}
