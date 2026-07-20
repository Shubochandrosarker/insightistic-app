"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
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
  cloudflare: "Cloudflare",
  engagement: "Engagement tracking",
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
  degraded: "Degraded",
  error: "Error",
  disabled: "Disabled",
  not_configured: "Not configured",
};

export default function IntegrationsPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const { data, loading } = useApi<{ sources: any[] }>(siteId ? `/sites/${siteId}/integrations` : null);

  if (!siteId)
    return (
      <EmptyState
        icon={<Plug size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site to see its data-source health here."
      />
    );

  if (loading) return <Spinner />;

  const sources = data?.sources || [];

  return (
    <div>
      <PageHeader title="Integrations" subtitle={`Data-source health for ${site?.name || "this site"}.`} />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {sources.map((s) => (
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
                  : `Set up ${SOURCE_LABEL[s.source] ?? s.source} in the Insightistic plugin to enable this.`}
              </p>
            )}
          </Card>
        ))}
      </div>
    </div>
  );
}
