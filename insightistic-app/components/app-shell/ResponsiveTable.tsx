import type { ReactNode } from "react";
import { ErrorState } from "@/components/dashboard/states";

export type Column<T> = {
  key: string;
  label: string;
  align?: "left" | "right";
  primary?: boolean; // shown as the card title on mobile
  render?: (row: T) => ReactNode;
};

/**
 * A table on md+ screens; stacked cards on mobile so rows never overflow or
 * shrink to unreadable text. Define columns once and get both layouts.
 */
export function ResponsiveTable<T>({
  columns,
  rows,
  empty = "No data.",
  rowKey,
  error = null,
  emptyMessage,
}: {
  columns: Column<T>[];
  rows: T[];
  empty?: string;
  /** Phase 3: when the fetch behind these rows failed, show an error card instead of pretending the table is just empty. */
  error?: string | null;
  /** Phase 3: alias for `empty`; takes precedence when both are provided. */
  emptyMessage?: string;
  rowKey: (row: T, i: number) => string | number;
}) {
  if (error) return <ErrorState message={error} />;
  if (!rows.length) return <p className="py-6 text-center text-sm text-muted">{emptyMessage ?? empty}</p>;

  const cell = (col: Column<T>, row: T) => (col.render ? col.render(row) : ((row as any)[col.key] ?? "—"));

  return (
    <>
      {/* Desktop / tablet table */}
      <div className="hidden overflow-x-auto md:block">
        <table className="w-full text-sm">
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c.key} className={`border-b border-line pb-2.5 px-2 text-[11px] font-semibold uppercase tracking-wide text-muted ${c.align === "right" ? "text-right" : "text-left"}`}>
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr key={rowKey(row, i)} className="group">
                {columns.map((c) => (
                  <td key={c.key} className={`border-b border-line py-3 px-2 text-fg group-last:border-0 ${c.align === "right" ? "text-right" : "text-left"}`}>
                    {cell(c, row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Mobile cards */}
      <div className="space-y-2.5 md:hidden">
        {rows.map((row, i) => {
          const primary = columns.find((c) => c.primary) ?? columns[0];
          const rest = columns.filter((c) => c !== primary);
          return (
            <div key={rowKey(row, i)} className="rounded-2xl border border-line bg-card2 p-3.5">
              <div className="mb-2 text-sm font-semibold text-fg">{cell(primary, row)}</div>
              <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5">
                {rest.map((c) => (
                  <div key={c.key} className="flex flex-col">
                    <dt className="text-[10px] uppercase tracking-wide text-muted">{c.label}</dt>
                    <dd className="text-sm text-fg">{cell(c, row)}</dd>
                  </div>
                ))}
              </dl>
            </div>
          );
        })}
      </div>
    </>
  );
}
