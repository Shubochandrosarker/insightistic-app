import { ReactNode } from "react";
export function DataTable({ head, rows, empty = "No data." }: { head: string[]; rows: ReactNode[][]; empty?: string }) {
  if (!rows.length) return <p className="py-4 text-sm text-slate-400">{empty}</p>;
  return (
    <table className="w-full text-sm">
      <thead>
        <tr>{head.map((h, i) => <th key={i} className={`border-b border-line py-2 px-1 text-slate-400 font-normal ${i ? "text-right" : "text-left"}`}>{h}</th>)}</tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i}>{r.map((c, j) => <td key={j} className={`border-b border-line/50 py-2 px-1 text-slate-200 ${j ? "text-right" : "text-left"}`}>{c}</td>)}</tr>
        ))}
      </tbody>
    </table>
  );
}
