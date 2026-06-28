import { ReactNode } from "react";

export function DataTable({
  head,
  rows,
  empty = "No data.",
  align,
}: {
  head: string[];
  rows: ReactNode[][];
  empty?: string;
  align?: ("left" | "right")[];
}) {
  if (!rows.length) return <p className="py-6 text-center text-sm text-muted">{empty}</p>;
  const isRight = (i: number) => (align?.[i] ?? (i ? "right" : "left")) === "right";
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr>
            {head.map((h, i) => (
              <th
                key={i}
                className={`border-b border-line pb-2.5 px-2 text-[11px] font-semibold uppercase tracking-wide text-muted ${
                  isRight(i) ? "text-right" : "text-left"
                }`}
              >
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} className="group">
              {r.map((c, j) => (
                <td
                  key={j}
                  className={`border-b border-line py-3 px-2 text-fg group-last:border-0 ${
                    isRight(j) ? "text-right" : "text-left"
                  }`}
                >
                  {c}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
