"use client";
import { Badge } from "@/components/ui/Badge";

/**
 * Shared pieces for Automation Studio (roadmap doc 19). The catalog payload
 * is the single source of truth for the rule builder: every picker option and
 * every form field on the page is rendered from it, so the UI can never offer
 * a trigger/action the backend cannot run — entries with status
 * "coming_soon" render disabled with the flag shown instead.
 */

export type CatalogField = {
  key: string;
  label: string;
  type: string; // "text" | "number" | "select"
  options?: string[];
};

export type CatalogEntry = {
  type: string;
  label: string;
  description?: string;
  status?: "active" | "coming_soon";
  config_fields?: CatalogField[];
  dispatched_by?: string[];
  rate_limit?: string;
};

export type AutomationCatalogPayload = {
  triggers?: CatalogEntry[];
  actions?: CatalogEntry[];
};

export type AutomationRule = {
  id: number | string;
  name: string;
  is_active?: boolean;
  trigger_type: string;
  trigger_config_json?: Record<string, any> | null;
  action_type: string;
  action_params_json?: Record<string, any> | null;
  last_fired_at?: string | null;
  last_run_status?: string | null;
  created_at?: string | null;
};

export type AutomationRun = {
  id: number | string;
  fired_at?: string | null;
  status?: string;
  trigger_context_json?: Record<string, any> | null;
  action_result_json?: Record<string, any> | null;
  error_message?: string | null;
};

/** Human label for a trigger/action type from its catalog entry; raw type as fallback. */
export function catalogLabel(list: CatalogEntry[] | undefined, type?: string | null): string {
  if (!type) return "—";
  return list?.find((e) => e.type === type)?.label ?? type;
}

/** Run status chip: success green, failed red, anything else neutral. */
export function RunStatusChip({ status }: { status?: string | null }) {
  if (!status) return <span className="text-xs text-muted">—</span>;
  const tone = status === "success" ? "green" : status === "failed" ? "red" : "slate";
  return (
    <Badge tone={tone as any} dot>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </Badge>
  );
}

/** Default form values for a catalog entry: selects preselect their first option. */
export function fieldDefaults(entry?: CatalogEntry): Record<string, string> {
  const out: Record<string, string> = {};
  for (const f of entry?.config_fields ?? []) {
    out[f.key] = f.type === "select" && f.options && f.options.length > 0 ? f.options[0] : "";
  }
  return out;
}

/**
 * Collect a config object from the builder's field values, shaped exactly as
 * the controller expects in `trigger_config` / `action_params`: selects and
 * text pass through as strings, numbers coerce when they parse.
 */
export function collectConfig(entry: CatalogEntry | undefined, values: Record<string, string>): Record<string, any> {
  const out: Record<string, any> = {};
  for (const f of entry?.config_fields ?? []) {
    if (!(f.key in values)) continue;
    const raw = (values[f.key] ?? "").trim();
    out[f.key] = f.type === "number" && raw !== "" && Number.isFinite(Number(raw)) ? Number(raw) : raw;
  }
  return out;
}

/** One-line summary of a run's trigger context for the runs log. */
export function contextSummary(ctx: unknown): string {
  if (ctx == null) return "—";
  if (typeof ctx !== "object") return String(ctx);
  try {
    const entries = Object.entries(ctx as Record<string, unknown>);
    if (entries.length === 0) return "—";
    return entries
      .map(([k, v]) => `${k}=${typeof v === "object" && v !== null ? JSON.stringify(v) : String(v)}`)
      .join(", ");
  } catch {
    return "—";
  }
}

const inputCls =
  "w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

/**
 * A single catalog-driven config field. The label, input type and options all
 * come from the catalog — the builder never hardcodes a trigger/action field.
 */
export function CatalogFieldInput({
  field,
  value,
  onChange,
}: {
  field: CatalogField;
  value: string;
  onChange: (next: string) => void;
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium text-muted">{field.label}</span>
      {field.type === "select" ? (
        <select className={inputCls} value={value} aria-label={field.label} onChange={(e) => onChange(e.target.value)}>
          {(field.options ?? []).map((opt) => (
            <option key={opt} value={opt}>
              {opt}
            </option>
          ))}
        </select>
      ) : (
        <input
          className={inputCls}
          type={field.type === "number" ? "number" : "text"}
          value={value}
          aria-label={field.label}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </label>
  );
}
