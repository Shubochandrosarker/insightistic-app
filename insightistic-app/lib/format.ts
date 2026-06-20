export const money = (n: number | string | undefined, cur = "USD") =>
  `${cur === "USD" ? "$" : cur + " "}${Number(n || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  })}`;

export const num = (n: number | undefined) => Number(n || 0).toLocaleString();

export const pct = (d: number | null | undefined) =>
  d === null || d === undefined ? "—" : `${d > 0 ? "+" : ""}${d}%`;

export const deltaTone = (d: number | null | undefined) =>
  d === null || d === undefined ? "text-slate-400" : d > 0 ? "text-emerald-600" : d < 0 ? "text-red-600" : "text-slate-400";
