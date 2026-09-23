"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDelete, apiGet, apiPost } from "@/lib/api";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { num } from "@/lib/format";
import { Building2, Plus, Users } from "lucide-react";

/**
 * Agency Portfolio (roadmap doc 20, Phase 13): every client site's health,
 * uptime and search on one screen. Unlike every other analytical page this is
 * NOT scoped to the selected site — it is the one org-wide surface, mirroring
 * GET /agency/portfolio on the backend. Client CRUD and site assignment are
 * owner/admin server-side; the UI keeps the buttons visible and answers a 403
 * with a graceful message instead of hiding the workflow (same bargain as the
 * crawl RunBar).
 */

type HealthState = "critical" | "warning" | "healthy" | "unknown";

type PortfolioSite = {
  site_id: number;
  site_name: string;
  domain?: string | null;
  client_id?: number | null;
  client_name?: string | null;
  health_score?: number | null;
  has_crawl_data?: boolean;
  health_state?: HealthState;
  uptime?: { availability_pct?: number | null; open_incidents?: number } | null;
  search?: { clicks?: number | null; impressions?: number | null } | null;
  critical_issues?: number;
  freshness?: { last_sync_at?: string | null; data_as_of?: string | null } | null;
};

type AgencyClient = {
  id: number;
  name: string;
  contact_email?: string | null;
  notes?: string | null;
  sites_count?: number;
};

const selectCls =
  "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";
const inputCls =
  "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

const HEALTH_OPTIONS = [
  { value: "all", label: "All states" },
  { value: "critical", label: "Critical" },
  { value: "warning", label: "Warning" },
  { value: "healthy", label: "Healthy" },
];

function portfolioPath(clientFilter: string, healthFilter: string): string {
  const sp = new URLSearchParams();
  if (clientFilter !== "all") sp.set("client_id", clientFilter);
  if (healthFilter !== "all") sp.set("health", healthFilter);
  const qs = sp.toString();
  return `/agency/portfolio${qs ? `?${qs}` : ""}`;
}

function healthTone(state?: HealthState): "red" | "amber" | "green" | "slate" {
  return state === "critical" ? "red" : state === "warning" ? "amber" : state === "healthy" ? "green" : "slate";
}

/** Write failures: a 403 explains who to ask, everything else shows the API's message. */
function writeMsg(e: any, fallback: string): { text: string; tone: "info" | "warn" | "bad" } {
  if (e?.status === 403) {
    return { text: "You don't have permission to manage agency clients — ask an organization owner or admin.", tone: "warn" };
  }
  return { text: e?.message || fallback, tone: "bad" };
}

function Tile({ id, label, value, caption }: { id: string; label: string; value: string; caption?: string }) {
  return (
    <div data-testid={id} className="rounded-2xl border border-line bg-card p-4 shadow-card">
      <p className="text-xs font-medium text-muted">{label}</p>
      <p className="mt-1 text-[26px] font-bold leading-tight text-fg">{value}</p>
      {caption && <p className="mt-0.5 text-xs text-muted">{caption}</p>}
    </div>
  );
}

function HealthCell({ row }: { row: PortfolioSite }) {
  if (row.health_state === "unknown" || row.has_crawl_data === false) {
    return <Badge tone="slate">Not crawled</Badge>;
  }
  return (
    <div className="flex items-center gap-2">
      <span className="font-semibold text-fg">{row.health_score != null ? Math.round(Number(row.health_score)) : "—"}</span>
      <Badge tone={healthTone(row.health_state)} dot>
        {(row.health_state ?? "unknown").charAt(0).toUpperCase() + (row.health_state ?? "unknown").slice(1)}
      </Badge>
    </div>
  );
}

export default function AgencyPortfolioPage() {
  const { sites } = useDashboard();

  const clients = useApi<any>("/agency/clients");
  const [clientFilter, setClientFilter] = useState("all");
  const [healthFilter, setHealthFilter] = useState("all");
  const portfolio = useApi<any>(portfolioPath(clientFilter, healthFilter));

  const [msg, setMsg] = useState<{ text: string; tone: "info" | "warn" | "bad" } | null>(null);
  const [newClient, setNewClient] = useState({ name: "", email: "" });
  const [adding, setAdding] = useState(false);
  // The open "assign sites" panel: which client, which ids are checked.
  const [assignFor, setAssignFor] = useState<number | null>(null);
  const [checked, setChecked] = useState<number[]>([]);
  const [prefilling, setPrefilling] = useState(false);
  const [savingAssign, setSavingAssign] = useState(false);

  const clientList: AgencyClient[] = Array.isArray(clients.data?.clients) ? clients.data.clients : [];
  const rows: PortfolioSite[] = Array.isArray(portfolio.data?.sites) ? portfolio.data.sites : [];
  const summary = portfolio.data?.summary ?? {};
  const crawledCount = rows.filter((r) => r.has_crawl_data).length;

  function refreshAll() {
    clients.reload();
    portfolio.reload();
  }

  function toggleChecked(siteId: number) {
    setChecked((prev) => (prev.includes(siteId) ? prev.filter((id) => id !== siteId) : [...prev, siteId]));
  }

  function addClient() {
    const name = newClient.name.trim();
    if (!name || adding) return;
    setAdding(true);
    setMsg(null);
    apiPost("/agency/clients", {
      name,
      ...(newClient.email.trim() ? { contact_email: newClient.email.trim() } : {}),
    })
      .then(() => {
        setNewClient({ name: "", email: "" });
        setMsg({ text: `Client "${name}" added.`, tone: "info" });
        refreshAll();
      })
      .catch((e: any) => setMsg(writeMsg(e, "Couldn't add the client. Try again in a moment.")))
      .finally(() => setAdding(false));
  }

  function deleteClient(client: AgencyClient) {
    if (!globalThis.confirm(`Delete client "${client.name}"? Sites will be unassigned, not deleted.`)) return;
    setMsg(null);
    apiDelete(`/agency/clients/${client.id}`)
      .then(() => {
        setMsg({ text: `Client "${client.name}" deleted — its sites are now unassigned.`, tone: "info" });
        refreshAll();
      })
      .catch((e: any) => setMsg(writeMsg(e, "Couldn't delete the client. Try again in a moment.")));
  }

  /** Open the assignment panel and prefill the checkbox list with the client's CURRENT sites (an unfiltered portfolio read). */
  function openAssign(client: AgencyClient) {
    setAssignFor(client.id);
    setChecked([]);
    setPrefilling(true);
    setMsg(null);
    apiGet("/agency/portfolio")
      .then((res: any) => {
        const all: PortfolioSite[] = Array.isArray(res?.sites) ? res.sites : [];
        setChecked(all.filter((r) => r.client_id === client.id).map((r) => r.site_id));
      })
      .catch(() => {
        // A failed prefill must not look like "no sites assigned" — the hint
        // below already spells out the sync semantics before saving.
        setMsg({ text: "Couldn't load the current assignment — check the list carefully before saving.", tone: "warn" });
      })
      .finally(() => setPrefilling(false));
  }

  function saveAssignment(client: AgencyClient) {
    if (savingAssign) return;
    setSavingAssign(true);
    setMsg(null);
    // Sync semantics: the checked ids are the client's EXACT site set —
    // sites left unchecked are unassigned (POST /agency/clients/{id}/sites).
    apiPost(`/agency/clients/${client.id}/sites`, { site_ids: checked })
      .then((res: any) => {
        setMsg({
          text: `${client.name} now has ${num(res?.sites_count ?? checked.length)} assigned site${checked.length === 1 ? "" : "s"}.`,
          tone: "info",
        });
        setAssignFor(null);
        refreshAll();
      })
      .catch((e: any) => setMsg(writeMsg(e, "Couldn't save the assignment. Try again in a moment.")))
      .finally(() => setSavingAssign(false));
  }

  const columns: Column<PortfolioSite>[] = [
    {
      key: "site",
      label: "Site",
      primary: true,
      render: (r) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-fg">{r.site_name}</p>
          {r.domain && (
            <p className="truncate font-mono text-xs text-muted" title={r.domain}>
              {r.domain}
            </p>
          )}
        </div>
      ),
    },
    { key: "client", label: "Client", render: (r) => (r.client_name ? <span>{r.client_name}</span> : <span className="text-muted">—</span>) },
    { key: "health", label: "Health", render: (r) => <HealthCell row={r} /> },
    {
      key: "uptime",
      label: "Uptime",
      render: (r) =>
        r.uptime ? (
          <span>
            {r.uptime.availability_pct != null ? `${Number(r.uptime.availability_pct).toFixed(1)}%` : "—"}
            {Number(r.uptime.open_incidents ?? 0) > 0 && (
              <span className="ml-1.5 text-xs font-semibold text-bad">{r.uptime.open_incidents} open</span>
            )}
          </span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: "search",
      label: "Search 28d",
      align: "right",
      render: (r) =>
        r.search ? (
          <span>
            {num(r.search.clicks ?? undefined)} <span className="text-muted">/ {num(r.search.impressions ?? undefined)}</span>
          </span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: "critical_issues",
      label: "Critical issues",
      align: "right",
      render: (r) =>
        Number(r.critical_issues ?? 0) > 0 ? (
          <span className="font-semibold text-bad">{r.critical_issues}</span>
        ) : (
          <span className="text-muted">0</span>
        ),
    },
    {
      key: "freshness",
      label: "Data as of",
      align: "right",
      render: (r) => <SourceFreshness label="As of" asOf={r.freshness?.data_as_of ?? null} />,
    },
  ];

  const assignClient = clientList.find((c) => c.id === assignFor) ?? null;

  return (
    <div>
      <PageHeader title="Agency Portfolio" subtitle="Every client site's health, uptime and search on one screen." />

      {msg && (
        <p role="status" className={`mb-4 text-xs ${msg.tone === "warn" ? "text-warn" : msg.tone === "bad" ? "text-bad" : "text-muted"}`}>
          {msg.text}
        </p>
      )}

      {portfolio.loading && !portfolio.data ? (
        <Spinner />
      ) : portfolio.error ? (
        <ErrorState message={portfolio.error} onRetry={portfolio.reload} />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Tile id="tile-sites" label="Sites" value={num(summary.sites)} />
            <Tile
              id="tile-health"
              label="Avg health"
              value={summary.avg_health != null ? String(Math.round(Number(summary.avg_health))) : "—"}
              caption={crawledCount > 0 ? `of ${crawledCount} crawled` : "no crawled sites yet"}
            />
            <Tile id="tile-critical" label="Sites with critical issues" value={num(summary.sites_with_critical)} />
            <Tile id="tile-clients" label="Clients" value={num(summary.clients)} />
          </div>

          <Card className="mt-4">
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <select
                className={selectCls}
                aria-label="Filter by client"
                value={clientFilter}
                onChange={(e) => setClientFilter(e.target.value)}
              >
                <option value="all">All clients</option>
                {clientList.map((c) => (
                  <option key={c.id} value={String(c.id)}>
                    {c.name}
                  </option>
                ))}
              </select>
              <select
                className={selectCls}
                aria-label="Filter by health"
                value={healthFilter}
                onChange={(e) => setHealthFilter(e.target.value)}
              >
                {HEALTH_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>

            <ResponsiveTable
              columns={columns}
              rows={rows}
              rowKey={(r) => r.site_id}
              emptyMessage="No sites match these filters yet — connect sites or widen the filters."
            />
          </Card>

          <Card className="mt-4" title="Clients" subtitle="Optional grouping: organization → clients → sites. Dropping a client never deletes its sites.">
            {clients.loading && !clients.data ? (
              <Spinner />
            ) : clients.error ? (
              <ErrorState message={clients.error} onRetry={clients.reload} />
            ) : (
              <>
                {clientList.length === 0 ? (
                  <p className="py-3 text-sm text-muted">
                    No clients yet — group your sites by customer to filter the portfolio above.
                  </p>
                ) : (
                  <ul className="divide-y divide-line">
                    {clientList.map((c) => (
                      <li key={c.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 text-sm">
                        <div className="min-w-0 flex-1">
                          <p className="font-medium text-fg">{c.name}</p>
                          <p className="truncate text-xs text-muted">
                            {c.contact_email || "no contact email"} · {num(c.sites_count)} site{Number(c.sites_count) === 1 ? "" : "s"}
                          </p>
                        </div>
                        <Button variant="ghost" className="px-3 py-1.5 text-xs" onClick={() => (assignFor === c.id ? setAssignFor(null) : openAssign(c))}>
                          <Users size={13} /> Assign sites
                        </Button>
                        <Button variant="danger" className="px-3 py-1.5 text-xs" onClick={() => deleteClient(c)}>
                          Delete
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}

                {assignClient && (
                  <div className="mt-4 rounded-2xl border border-line bg-card2 p-4" data-testid="assign-panel">
                    <p className="text-sm font-semibold text-fg">Assign sites to {assignClient.name}</p>
                    <p className="mt-0.5 text-xs text-muted">
                      The checked set becomes this client&apos;s exact site list — unchecked sites are unassigned.
                    </p>
                    {prefilling ? (
                      <Spinner label="Loading current assignment…" />
                    ) : (
                      <ul className="mt-3 space-y-1.5">
                        {sites.map((s) => (
                          <li key={s.id}>
                            <label className="flex items-center gap-2 text-sm text-fg">
                              <input
                                type="checkbox"
                                aria-label={`Assign ${s.name}`}
                                checked={checked.includes(s.id)}
                                onChange={() => toggleChecked(s.id)}
                              />
                              {s.name}
                              {s.domain && <span className="font-mono text-xs text-muted">{s.domain}</span>}
                            </label>
                          </li>
                        ))}
                      </ul>
                    )}
                    <div className="mt-3 flex gap-2">
                      <Button className="px-3 py-1.5 text-xs" onClick={() => saveAssignment(assignClient)} disabled={savingAssign || prefilling}>
                        Save assignment
                      </Button>
                      <Button variant="ghost" className="px-3 py-1.5 text-xs" onClick={() => setAssignFor(null)}>
                        Cancel
                      </Button>
                    </div>
                  </div>
                )}

                <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                  <div>
                    <label className="mb-1 block text-xs font-medium text-muted" htmlFor="new-client-name">
                      Client name
                    </label>
                    <input
                      id="new-client-name"
                      className={inputCls}
                      placeholder="Acme Co"
                      value={newClient.name}
                      onChange={(e) => setNewClient((f) => ({ ...f, name: e.target.value }))}
                    />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-medium text-muted" htmlFor="new-client-email">
                      Contact email (optional)
                    </label>
                    <input
                      id="new-client-email"
                      className={inputCls}
                      type="email"
                      placeholder="owner@acme.co"
                      value={newClient.email}
                      onChange={(e) => setNewClient((f) => ({ ...f, email: e.target.value }))}
                    />
                  </div>
                  <Button onClick={addClient} disabled={adding || !newClient.name.trim()}>
                    <Plus size={14} /> Add client
                  </Button>
                </div>
              </>
            )}
          </Card>
        </>
      )}

      {/* Organization-level page: no site picker needed, but an empty org still needs an honest state. */}
      {!portfolio.loading && !portfolio.error && portfolio.data && rows.length === 0 && clientFilter === "all" && sites.length === 0 && (
        <div className="mt-4">
          <EmptyAgencyHint />
        </div>
      )}
    </div>
  );
}

function EmptyAgencyHint() {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-line bg-card px-6 py-10 text-center">
      <div className="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-brand/10 text-brand">
        <Building2 size={20} />
      </div>
      <h3 className="text-sm font-semibold text-fg">No sites in your organization yet</h3>
      <p className="mt-1 max-w-sm text-sm text-muted">Add a website (any platform) and it will appear in the portfolio.</p>
    </div>
  );
}
