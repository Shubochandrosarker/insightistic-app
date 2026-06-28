import { pct, deltaTone } from "@/lib/format";
import { Sparkline } from "./Charts";

export function KpiCard({
  label,
  value,
  delta,
  series,
  color = "#00C04B",
}: {
  label: string;
  value: string | number;
  delta?: number | null;
  series?: { v: number }[];
  color?: string;
}) {
  const up = delta != null && delta > 0;
  const down = delta != null && delta < 0;
  return (
    <div className="rounded-2xl border border-line bg-card p-4 shadow-card">
      <div className="flex items-start justify-between">
        <span className="text-xs font-medium text-muted">{label}</span>
        {delta !== undefined && delta !== null && (
          <span className={`text-xs font-semibold ${deltaTone(delta)}`}>
            {up ? "↑" : down ? "↓" : ""} {pct(delta).replace("+", "")}
          </span>
        )}
      </div>
      <div className="mt-1.5 text-[26px] font-bold leading-tight text-fg">{value}</div>
      {series && series.length > 1 && (
        <div className="mt-2 -mb-1">
          <Sparkline data={series} dataKey="v" color={color} height={36} />
        </div>
      )}
    </div>
  );
}
