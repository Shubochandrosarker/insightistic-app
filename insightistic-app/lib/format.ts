export const money = (n: number | string | undefined, cur = "USD") =>
  `${cur === "USD" ? "$" : cur + " "}${Number(n || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  })}`;

/** Compact currency for big KPI numbers ($48,920 not $48,920.00). */
export const money0 = (n: number | string | undefined, cur = "USD") =>
  `${cur === "USD" ? "$" : cur + " "}${Number(n || 0).toLocaleString(undefined, {
    maximumFractionDigits: 0,
  })}`;

export const num = (n: number | undefined) => Number(n || 0).toLocaleString();

export const pct = (d: number | null | undefined) =>
  d === null || d === undefined ? "—" : `${d > 0 ? "+" : ""}${d}%`;

export const deltaTone = (d: number | null | undefined) =>
  d === null || d === undefined ? "text-muted" : d > 0 ? "text-good" : d < 0 ? "text-bad" : "text-muted";

export const initials = (name?: string) =>
  (name || "?")
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join("") || "?";

export function timeAgo(iso?: string | null): string {
  if (!iso) return "—";
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "—";
  const s = Math.max(1, Math.floor((Date.now() - then) / 1000));
  if (s < 60) return `${s}s ago`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.floor(h / 24);
  return `${d}d ago`;
}
