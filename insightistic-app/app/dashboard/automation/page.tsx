"use client";
import { useEffect, useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDelete, apiGet, apiPatch, apiPost } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { Zap } from "lucide-react";
import {
  CatalogFieldInput,
  RunStatusChip,
  catalogLabel,
  collectConfig,
  contextSummary,
  fieldDefaults,
  type AutomationRule,
  type AutomationRun,
  type CatalogEntry,
} from "@/components/dashboard/automation";
import { timeAgo } from "@/lib/format";

/**
 * Automation Studio (roadmap doc 19, Phase 14): "when X happens, do Y". The
 * rule builder is entirely catalog-driven — GET /sites/{site}/automation-rules/
 * catalog is the single source of truth for pickers and config fields, so the
 * UI can never offer a trigger/action the dispatcher does not actually run
 * (coming_soon entries render disabled with the flag shown). Every write is
 * owner/admin server-side; a 403 gets a graceful message, not a hidden button.
 */

const inputCls =
  "w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

type Msg = { text: string; tone: "info" | "warn" | "bad" } | null;

function writeMsg(e: any, fallback: string): Msg {
  if (e?.status === 403) {
    return { text: "You don't have permission to manage automation rules — ask an organization owner or admin.", tone: "warn" };
  }
  return { text: e?.message || fallback, tone: "bad" };
}

export default function AutomationStudioPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);

  const rules = useApi<any>(siteId ? `/sites/${siteId}/automation-rules` : null);
  const catalog = useApi<any>(siteId ? `/sites/${siteId}/automation-rules/catalog` : null);

  const ruleList: AutomationRule[] = Array.isArray(rules.data?.automation_rules) ? rules.data.automation_rules : [];
  const triggers: CatalogEntry[] = Array.isArray(catalog.data?.triggers) ? catalog.data.triggers : [];
  const actions: CatalogEntry[] = Array.isArray(catalog.data?.actions) ? catalog.data.actions : [];

  const triggerEntry = (type?: string | null) => triggers.find((t) => t.type === type);
  const actionEntry = (type?: string | null) => actions.find((a) => a.type === type);

  const [msg, setMsg] = useState<Msg>(null);
  const [busyRuleId, setBusyRuleId] = useState<number | string | null>(null);

  // Rule builder (create + edit share the same inline card).
  const [builderOpen, setBuilderOpen] = useState(false);
  const [editing, setEditing] = useState<AutomationRule | null>(null);
  const [formName, setFormName] = useState("");
  const [triggerType, setTriggerType] = useState("");
  const [triggerCfg, setTriggerCfg] = useState<Record<string, string>>({});
  const [actionType, setActionType] = useState("");
  const [actionCfg, setActionCfg] = useState<Record<string, string>>({});
  const [savingRule, setSavingRule] = useState(false);

  // Runs log (one rule expanded at a time).
  const [openRunsId, setOpenRunsId] = useState<number | string | null>(null);
  const [runs, setRuns] = useState<AutomationRun[]>([]);
  const [runsLoading, setRunsLoading] = useState(false);
  const [runsError, setRunsError] = useState<string | null>(null);
  const [runsTick, setRunsTick] = useState(0);

  useEffect(() => {
    if (!siteId || openRunsId == null) return;
    let cancelled = false;
    setRunsLoading(true);
    setRunsError(null);
    apiGet(`/sites/${siteId}/automation-rules/${openRunsId}/runs`)
      .then((res: any) => {
        if (!cancelled) setRuns(Array.isArray(res?.automation_runs) ? res.automation_runs : []);
      })
      .catch((e: any) => {
        if (!cancelled) setRunsError(e?.message || "Couldn't load the runs log.");
      })
      .finally(() => {
        if (!cancelled) setRunsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [siteId, openRunsId, runsTick]);

  function startNew() {
    setEditing(null);
    setFormName("");
    setTriggerType("");
    setTriggerCfg({});
    setActionType("");
    setActionCfg({});
    setMsg(null);
    setBuilderOpen(true);
  }

  function startEdit(rule: AutomationRule) {
    setEditing(rule);
    setFormName(rule.name ?? "");
    setTriggerType(rule.trigger_type ?? "");
    // Prefill from the stored config, keeping catalog defaults for keys the
    // stored config doesn't have.
    setTriggerCfg({ ...fieldDefaults(triggerEntry(rule.trigger_type)), ...toStrings(rule.trigger_config_json) });
    setActionType(rule.action_type ?? "");
    setActionCfg({ ...fieldDefaults(actionEntry(rule.action_type)), ...toStrings(rule.action_params_json) });
    setMsg(null);
    setBuilderOpen(true);
  }

  function closeBuilder() {
    setBuilderOpen(false);
    setEditing(null);
  }

  function toStrings(cfg?: Record<string, any> | null): Record<string, string> {
    const out: Record<string, string> = {};
    if (cfg && typeof cfg === "object") {
      for (const [k, v] of Object.entries(cfg)) out[k] = v == null ? "" : String(v);
    }
    return out;
  }

  function pickTrigger(type: string) {
    setTriggerType(type);
    setTriggerCfg(fieldDefaults(triggerEntry(type)));
  }

  function pickAction(type: string) {
    setActionType(type);
    setActionCfg(fieldDefaults(actionEntry(type)));
  }

  function saveRule() {
    if (!siteId || savingRule) return;
    const tEntry = triggerEntry(triggerType);
    const aEntry = actionEntry(actionType);
    setSavingRule(true);
    setMsg(null);

    const triggerConfig = collectConfig(tEntry, triggerCfg);
    const actionParams = collectConfig(aEntry, actionCfg);
    // The controller takes the webhook secret top-level (stored encrypted,
    // never echoed back) — only send it when the user typed one, so an edit
    // with a blank field keeps the existing secret.
    const secret = typeof actionParams.webhook_secret === "string" ? actionParams.webhook_secret.trim() : "";
    delete actionParams.webhook_secret;

    const body: Record<string, any> = {
      name: formName.trim(),
      trigger_type: triggerType,
      trigger_config: triggerConfig,
      action_type: actionType,
      action_params: actionParams,
    };
    if (secret) body.webhook_secret = secret;

    const req = editing
      ? apiPatch(`/sites/${siteId}/automation-rules/${editing.id}`, body)
      : apiPost(`/sites/${siteId}/automation-rules`, body);

    req
      .then(() => {
        setMsg({ text: editing ? `Rule "${body.name}" saved.` : `Rule "${body.name}" created — it runs itself from now on.`, tone: "info" });
        closeBuilder();
        rules.reload();
      })
      .catch((e: any) => setMsg(writeMsg(e, "Couldn't save the rule. Try again in a moment.")))
      .finally(() => setSavingRule(false));
  }

  function toggleRule(rule: AutomationRule) {
    if (!siteId || busyRuleId) return;
    setBusyRuleId(rule.id);
    setMsg(null);
    apiPatch(`/sites/${siteId}/automation-rules/${rule.id}`, { is_active: !rule.is_active })
      .then(() => rules.reload())
      .catch((e: any) => setMsg(writeMsg(e, "Couldn't update the rule. Try again in a moment.")))
      .finally(() => setBusyRuleId(null));
  }

  function runOnce(rule: AutomationRule) {
    if (!siteId || busyRuleId) return;
    setBusyRuleId(rule.id);
    setMsg(null);
    apiPost(`/sites/${siteId}/automation-rules/${rule.id}/run`, {})
      .then(() => {
        setMsg({ text: `Rule "${rule.name}" fired — check its runs log for the result.`, tone: "info" });
        rules.reload();
        if (openRunsId === rule.id) setRunsTick((t) => t + 1);
      })
      .catch((e: any) => setMsg(writeMsg(e, "Couldn't fire the rule. Try again in a moment.")))
      .finally(() => setBusyRuleId(null));
  }

  function deleteRule(rule: AutomationRule) {
    if (!siteId || busyRuleId) return;
    if (!globalThis.confirm(`Delete automation rule "${rule.name}"? Its run history is deleted with it.`)) return;
    setBusyRuleId(rule.id);
    setMsg(null);
    apiDelete(`/sites/${siteId}/automation-rules/${rule.id}`)
      .then(() => {
        if (openRunsId === rule.id) setOpenRunsId(null);
        setMsg({ text: `Rule "${rule.name}" deleted.`, tone: "info" });
        rules.reload();
      })
      .catch((e: any) => setMsg(writeMsg(e, "Couldn't delete the rule. Try again in a moment.")))
      .finally(() => setBusyRuleId(null));
  }

  function toggleRuns(rule: AutomationRule) {
    setOpenRunsId((cur) => (cur === rule.id ? null : rule.id));
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<Zap size={20} />}
        title="No website yet"
        hint="Add a website to build automation rules for it — any platform works."
      />
    );

  const columns: Column<AutomationRule>[] = [
    { key: "name", label: "Name", primary: true, render: (r) => <span className="font-medium text-fg">{r.name}</span> },
    { key: "trigger", label: "Trigger", render: (r) => catalogLabel(triggers, r.trigger_type) },
    { key: "action", label: "Action", render: (r) => catalogLabel(actions, r.action_type) },
    {
      key: "active",
      label: "Active",
      render: (r) => (
        <label className="inline-flex items-center gap-2 text-xs text-muted" title={r.is_active ? "Active — click to pause" : "Paused — click to activate"}>
          <input
            type="checkbox"
            aria-label={`Toggle ${r.name}`}
            checked={!!r.is_active}
            disabled={busyRuleId === r.id}
            onChange={() => toggleRule(r)}
          />
          {r.is_active ? "On" : "Paused"}
        </label>
      ),
    },
    {
      key: "last_fired",
      label: "Last fired",
      align: "right",
      render: (r) => <SourceFreshness label="Fired" asOf={r.last_fired_at ?? null} />,
    },
    { key: "last_status", label: "Last status", align: "right", render: (r) => <RunStatusChip status={r.last_run_status} /> },
    {
      key: "actions",
      label: "Actions",
      align: "right",
      render: (r) => (
        <div className="flex flex-wrap justify-end gap-1.5">
          <Button variant="ghost" className="px-2.5 py-1 text-xs" disabled={busyRuleId === r.id} onClick={() => runOnce(r)} title="Fire this rule now, for testing">
            Run once
          </Button>
          <Button variant="ghost" className="px-2.5 py-1 text-xs" onClick={() => startEdit(r)}>
            Edit
          </Button>
          <Button variant="ghost" className="px-2.5 py-1 text-xs" onClick={() => toggleRuns(r)}>
            {openRunsId === r.id ? "Hide runs" : "Runs"}
          </Button>
          <Button variant="danger" className="px-2.5 py-1 text-xs" disabled={busyRuleId === r.id} onClick={() => deleteRule(r)}>
            Delete
          </Button>
        </div>
      ),
    },
  ];

  const tEntry = triggerEntry(triggerType);
  const aEntry = actionEntry(actionType);
  const openRunsRule = ruleList.find((r) => r.id === openRunsId) ?? null;
  const canSave = formName.trim() !== "" && !!triggerType && !!actionType;

  const runColumns: Column<AutomationRun>[] = [
    { key: "fired_at", label: "Fired", render: (r) => <span title={r.fired_at ?? undefined}>{timeAgo(r.fired_at ?? null)}</span> },
    {
      key: "context",
      label: "Trigger context",
      primary: true,
      render: (r) => <span className="break-all font-mono text-xs text-muted">{contextSummary(r.trigger_context_json)}</span>,
    },
    {
      key: "result",
      label: "Result",
      render: (r) => (
        <div className="flex flex-col items-start gap-1">
          <RunStatusChip status={r.status} />
          {r.status === "failed" && r.error_message && <span className="text-xs text-bad">{r.error_message}</span>}
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Automation Studio"
        subtitle={`${site?.name ? `${site.name} · ` : ""}When X happens, do Y — rules run themselves.`}
        right={
          !builderOpen ? (
            <Button onClick={startNew}>
              <Zap size={14} /> New rule
            </Button>
          ) : undefined
        }
      />

      {msg && (
        <p role="status" className={`mb-4 text-xs ${msg.tone === "warn" ? "text-warn" : msg.tone === "bad" ? "text-bad" : "text-muted"}`}>
          {msg.text}
        </p>
      )}

      {builderOpen && (
        <Card title={editing ? `Edit rule — ${editing.name}` : "New rule"} subtitle="Pick a trigger, pick an action, wire the details.">
          <div className="space-y-4">
            <label className="block max-w-md">
              <span className="mb-1 block text-xs font-medium text-muted">Rule name</span>
              <input className={inputCls} aria-label="Rule name" placeholder="Nightly rank watch" value={formName} onChange={(e) => setFormName(e.target.value)} />
            </label>

            <div className="grid gap-4 lg:grid-cols-2">
              <div>
                <label className="block">
                  <span className="mb-1 block text-xs font-medium text-muted">When this happens…</span>
                  <select className={inputCls} aria-label="Trigger type" value={triggerType} onChange={(e) => pickTrigger(e.target.value)}>
                    <option value="">Choose a trigger…</option>
                    {triggers.map((t) => (
                      <option key={t.type} value={t.type} disabled={t.status === "coming_soon"}>
                        {t.label}
                        {t.status === "coming_soon" ? " — coming soon" : ""}
                      </option>
                    ))}
                  </select>
                </label>
                {tEntry?.description && <p className="mt-2 text-xs text-muted">{tEntry.description}</p>}
                {tEntry?.rate_limit && <p className="mt-1 text-xs text-warn">{tEntry.rate_limit}</p>}
                {tEntry && (tEntry.config_fields ?? []).length > 0 && (
                  <div className="mt-3 grid gap-3 sm:grid-cols-2" data-testid="trigger-config-fields">
                    {(tEntry.config_fields ?? []).map((f) => (
                      <CatalogFieldInput key={f.key} field={f} value={triggerCfg[f.key] ?? ""} onChange={(v) => setTriggerCfg((c) => ({ ...c, [f.key]: v }))} />
                    ))}
                  </div>
                )}
              </div>

              <div>
                <label className="block">
                  <span className="mb-1 block text-xs font-medium text-muted">…do this</span>
                  <select className={inputCls} aria-label="Action type" value={actionType} onChange={(e) => pickAction(e.target.value)}>
                    <option value="">Choose an action…</option>
                    {actions.map((a) => (
                      <option key={a.type} value={a.type} disabled={a.status === "coming_soon"}>
                        {a.label}
                        {a.status === "coming_soon" ? " — coming soon" : ""}
                      </option>
                    ))}
                  </select>
                </label>
                {aEntry?.description && <p className="mt-2 text-xs text-muted">{aEntry.description}</p>}
                {aEntry && (aEntry.config_fields ?? []).length > 0 && (
                  <div className="mt-3 grid gap-3 sm:grid-cols-2" data-testid="action-config-fields">
                    {(aEntry.config_fields ?? []).map((f) => (
                      <CatalogFieldInput key={f.key} field={f} value={actionCfg[f.key] ?? ""} onChange={(v) => setActionCfg((c) => ({ ...c, [f.key]: v }))} />
                    ))}
                  </div>
                )}
              </div>
            </div>

            <div className="flex gap-2">
              <Button onClick={saveRule} disabled={!canSave || savingRule}>
                {editing ? "Save changes" : "Create rule"}
              </Button>
              <Button variant="ghost" onClick={closeBuilder}>
                Cancel
              </Button>
            </div>
          </div>
        </Card>
      )}

      <Card className="mt-4" title="Rules" subtitle="Every execution is logged — success or failure, never silent.">
        {rules.loading && !rules.data ? (
          <Spinner />
        ) : rules.error ? (
          <ErrorState message={rules.error} onRetry={rules.reload} />
        ) : ruleList.length === 0 ? (
          <EmptyState
            icon={<Zap size={20} />}
            title="No automation rules yet"
            hint="Create one and let Insightistic watch for you."
            action={
              !builderOpen ? (
                <Button onClick={startNew}>
                  <Zap size={14} /> New rule
                </Button>
              ) : undefined
            }
          />
        ) : (
          <ResponsiveTable columns={columns} rows={ruleList} rowKey={(r) => r.id} emptyMessage="No automation rules yet." />
        )}
      </Card>

      {openRunsRule && (
        <Card
          className="mt-4"
          title={`Runs — ${openRunsRule.name}`}
          subtitle="Last 100 firings, newest first."
          right={
            <Button variant="ghost" className="px-3 py-1.5 text-xs" onClick={() => setOpenRunsId(null)}>
              Close
            </Button>
          }
        >
          {runsLoading ? (
            <Spinner label="Loading runs…" />
          ) : runsError ? (
            <ErrorState message={runsError} />
          ) : (
            <ResponsiveTable columns={runColumns} rows={runs} rowKey={(r) => r.id} emptyMessage="This rule has never fired — use Run once to test it." />
          )}
        </Card>
      )}
    </div>
  );
}
