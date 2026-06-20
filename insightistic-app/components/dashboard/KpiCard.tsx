import { pct, deltaTone } from "@/lib/format";
export function KpiCard({ label, value, delta }: { label: string; value: string | number; delta?: number | null }) {
  return (
    <div className="rounded-xl border border-line bg-panel p-4">
      <div className="text-xs uppercase tracking-wide text-slate-400">{label}</div>
      <div className="mt-1 text-2xl font-semibold text-white">{value}</div>
      {delta !== undefined && <div className={`mt-1 text-xs ${deltaTone(delta)}`}>{pct(delta)}</div>}
    </div>
  );
}
