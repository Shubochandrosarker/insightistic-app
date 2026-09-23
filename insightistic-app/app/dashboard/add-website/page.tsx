"use client";

import { useMemo, useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { track } from "@/lib/track";
import { Globe, Search, CheckCircle2, Loader2, ExternalLink } from "lucide-react";

interface GoogleAccountStatus {
  connected: boolean;
  configured: boolean;
  account: { account_email?: string; account_id?: string } | null;
}

interface GscProperty {
  property: string;
  property_type: "domain" | "url_prefix";
  permission_level: string;
  domain: string;
  domain_key?: string;
  matched_site: { id: number; name: string } | null;
}

interface ImportResult {
  created: { site_id: number; name: string; domain: string; property: string; is_new: boolean }[];
  attached: { site_id: number; name: string; domain: string; property: string; is_new: boolean }[];
  skipped: { property: string; reason: string; primary_property?: string | null }[];
}

const REASONS: Record<string, string> = {
  not_readable: "This Google account can't read that property.",
  site_limit_reached: "Your plan's site limit is reached — upgrade to import more.",
  same_site_property: "Another Search Console property for this website was selected.",
};

export default function AddWebsitePage() {
  const { reloadSites, setSiteId } = useDashboard();

  // Shared state
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Option A — GSC import
  const [google, setGoogle] = useState<GoogleAccountStatus | null>(null);
  const [props, setProps] = useState<GscProperty[] | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [result, setResult] = useState<ImportResult | null>(null);

  // Option B — URL
  const [url, setUrl] = useState("");

  const selectable = useMemo(
    () => (props ?? []).filter((p) => p.permission_level !== "siteRestrictedUser"),
    [props],
  );
  const propertyGroups = useMemo(() => {
    const groups = new Map<string, GscProperty[]>();
    for (const property of selectable) {
      const key = property.domain_key || property.domain.toLowerCase();
      groups.set(key, [...(groups.get(key) || []), property]);
    }
    return [...groups.entries()];
  }, [selectable]);

  async function loadGoogle() {
    setBusy(true);
    setError(null);
    try {
      const status: GoogleAccountStatus = await apiGet("/integrations/google/account");
      setGoogle(status);
      if (status.connected) {
        const r = await apiGet("/integrations/google/gsc/properties");
        setProps(r.properties ?? []);
      }
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function connectGoogle() {
    setBusy(true);
    setError(null);
    try {
      const r = await apiPost("/integrations/google/account/authorize-url");
      window.location.href = r.authorize_url;
    } catch (e: any) {
      setError(e.message);
      setBusy(false);
    }
  }

  function toggle(property: GscProperty) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(property.property)) {
        next.delete(property.property);
      } else {
        const key = property.domain_key || property.domain.toLowerCase();
        for (const candidate of selectable) {
          const candidateKey = candidate.domain_key || candidate.domain.toLowerCase();
          if (candidateKey === key) next.delete(candidate.property);
        }
        next.add(property.property);
      }
      return next;
    });
  }

  async function importSelected() {
    if (selected.size === 0) return;
    setBusy(true);
    setError(null);
    try {
      const r: ImportResult = await apiPost("/integrations/google/gsc/import", {
        properties: [...selected],
      });
      setResult(r);
      setProps(null);
      setSelected(new Set());
      await reloadSites();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function addByUrl() {
    if (!url.trim()) return;
    setBusy(true);
    setError(null);
    try {
      const r = await apiPost("/sites", { domain: url.trim() });
      await reloadSites();
      if (r.site?.id) setSiteId(r.site.id);
      track("site_added", { method: "url" });
      window.location.href = "/dashboard";
    } catch (e: any) {
      setError(e.message);
      setBusy(false);
    }
  }

  const googleReady = google === null ? null : google.connected;

  return (
    <div>
      <PageHeader
        title="Add a website"
        subtitle="Any website works — WordPress, Shopify, Laravel, Webflow, static or custom. No plugin required."
      />

      {error && (
        <div className="mb-5 rounded-xl border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">
          {error}
        </div>
      )}

      {result && (
        <Card className="mb-5" title="Import complete">
          <div className="space-y-2 text-sm">
            {result.created.length > 0 && (
              <p className="text-good">
                <CheckCircle2 size={14} className="mr-1.5 inline" />
                {result.created.length} website{result.created.length > 1 ? "s" : ""} created from Search
                Console — initial search sync, crawl, uptime monitoring and PageSpeed have been queued.
              </p>
            )}
            {result.attached.length > 0 && (
              <p className="text-muted">
                Search Console attached to {result.attached.length} existing project
                {result.attached.length > 1 ? "s" : ""}.
              </p>
            )}
            {result.skipped.map((s) => (
              <p key={s.property} className="text-warn">
                Skipped {s.property}: {REASONS[s.reason] ?? s.reason}
              </p>
            ))}
          </div>
          <div className="mt-4">
            <Button onClick={() => (window.location.href = "/dashboard")}>
              Go to dashboard <ExternalLink size={14} className="ml-1" />
            </Button>
          </div>
        </Card>
      )}

      <div className="grid gap-5 lg:grid-cols-2">
        {/* Option A — recommended */}
        <Card
          title="Import from Google Search Console"
          subtitle="Recommended — starts importing up to 16 months of Search Console history."
        >
          <div className="space-y-4">
            {google === null && (
              <div>
                <p className="mb-3 text-sm text-muted">
                  Connect Google once for your whole workspace — every Search Console property and
                  GA4 account you can access becomes importable. Read-only access.
                </p>
                <Button onClick={loadGoogle} disabled={busy}>
                  {busy ? <Loader2 size={14} className="mr-1 animate-spin" /> : <Search size={14} className="mr-1" />}
                  Start with Google
                </Button>
              </div>
            )}

            {googleReady === false && (
              <div>
                <p className="mb-3 text-sm text-warn">
                  {google?.configured === false
                    ? "Google sign-in isn't configured on this server yet. Use the URL option for now, or ask support to enable it."
                    : "Connect your Google account to import your Search Console properties."}
                </p>
                {google?.configured !== false && (
                  <Button onClick={connectGoogle} disabled={busy}>
                    {busy ? "Redirecting…" : "Connect Google"}
                  </Button>
                )}
              </div>
            )}

            {googleReady && props === null && (
              <div>
                <p className="mb-2 text-sm text-good">
                  Connected as {google?.account?.account_email ?? "your Google account"}.
                </p>
                <Button onClick={loadGoogle} disabled={busy}>
                  {busy ? <Loader2 size={14} className="mr-1 animate-spin" /> : null} Load Search Console properties
                </Button>
              </div>
            )}

            {googleReady && props !== null && (
              <div>
                <p className="mb-3 text-sm text-muted">
                  Select one Search Console property per website. Domain properties are preferred;
                  URL-prefix properties for the same host cannot create a second project. Matched
                  projects will receive the selected property instead of a duplicate.
                </p>
                {selectable.length === 0 ? (
                  <p className="text-sm text-warn">
                    No readable Search Console properties found for this Google account.
                  </p>
                ) : (
                  <>
                    <div className="max-h-72 space-y-1.5 overflow-y-auto pr-1">
                      {propertyGroups.map(([domain, group]) => (
                        <div key={domain} className="space-y-1.5">
                          <p className="px-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted">
                            {domain}
                          </p>
                          {group.map((p) => (
                            <label
                              key={p.property}
                              className="flex cursor-pointer items-center gap-3 rounded-lg border border-line bg-card2 px-3 py-2 hover:border-brand/50"
                            >
                              <input
                                type="checkbox"
                                checked={selected.has(p.property)}
                                onChange={() => toggle(p)}
                                className="h-4 w-4 accent-[var(--brand)]"
                              />
                              <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm text-fg">{p.property}</span>
                                <span className="block truncate text-xs text-muted">
                                  {p.property_type === "domain" ? "Domain property" : "URL prefix"}
                                  {p.matched_site ? ` · already in workspace (${p.matched_site.name})` : ""}
                                </span>
                              </span>
                              <Badge tone={p.permission_level === "siteOwner" ? "green" : "slate"}>
                                {p.permission_level === "siteOwner" ? "Owner" : "Full user"}
                              </Badge>
                            </label>
                          ))}
                        </div>
                      ))}
                    </div>
                    <div className="mt-4">
                      <Button onClick={importSelected} disabled={busy || selected.size === 0}>
                        {busy ? "Importing…" : `Import ${selected.size || ""} website${selected.size === 1 ? "" : "s"}`}
                      </Button>
                    </div>
                  </>
                )}
              </div>
            )}
          </div>
        </Card>

        {/* Option B — URL */}
        <Card
          title="Enter a website URL"
          subtitle="Works for any platform — audit, uptime and PageSpeed are queued immediately."
        >
          <div className="space-y-4">
            <Field
              label="Website URL"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://example.com"
            />
            <p className="text-sm text-muted">
              Insightistic queues a site audit, uptime monitoring, SSL tracking and a PageSpeed run
              immediately — no plugin, no code changes. You can connect Search Console, GA4, Bing
              and Cloudflare afterwards for search and traffic data.
            </p>
            <Button onClick={addByUrl} disabled={busy || !url.trim()}>
              {busy ? <Loader2 size={14} className="mr-1 animate-spin" /> : <Globe size={14} className="mr-1" />}
              Add website
            </Button>
            <p className="text-xs text-muted">
              WordPress detected later? The optional plugin adds WooCommerce, products, orders and
              CMS-specific fixes on top — never required.
            </p>
          </div>
        </Card>
      </div>
    </div>
  );
}
