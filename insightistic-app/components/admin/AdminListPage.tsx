"use client";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { Search } from "lucide-react";

export type AdminFilter = { key: string; label: string; options: { value: string; label: string }[] };

/**
 * Generic Super-Admin list page: search + filters + paginated ResponsiveTable.
 * `extract` maps the API JSON to { rows, meta } (defaults to a Laravel paginator).
 */
export function AdminListPage<T>({
  title,
  subtitle,
  endpoint,
  columns,
  rowKey,
  searchPlaceholder = "Search…",
  filters = [],
  extract,
  empty = "Nothing here yet.",
  actions,
}: {
  title: string;
  subtitle?: string;
  endpoint: string;
  columns: Column<T>[];
  rowKey: (row: T, i: number) => string | number;
  searchPlaceholder?: string;
  filters?: AdminFilter[];
  extract?: (json: any) => { rows: T[]; meta: { current_page: number; last_page: number; total: number } };
  empty?: string;
  actions?: (row: T, reload: () => void) => ReactNode;
}) {
  const [search, setSearch] = useState("");
  const [applied, setApplied] = useState("");
  const [filterValues, setFilterValues] = useState<Record<string, string>>({});
  const [page, setPage] = useState(1);

  // Debounce the search box and reset to page 1 on any query change.
  useEffect(() => {
    const t = setTimeout(() => { setApplied(search); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const path = useMemo(() => {
    const p = new URLSearchParams();
    if (applied) p.set("search", applied);
    Object.entries(filterValues).forEach(([k, v]) => v && p.set(k, v));
    p.set("page", String(page));
    return `${endpoint}?${p.toString()}`;
  }, [endpoint, applied, filterValues, page]);

  const { data, loading, error, reload } = useApi<any>(path);

  const cols = useMemo<Column<T>[]>(
    () => (actions ? [...columns, { key: "__actions", label: "Actions", align: "right", render: (row: T) => actions(row, reload) }] : columns),
    [columns, actions, reload],
  );

  const parsed = useMemo(() => {
    if (!data) return null;
    if (extract) return extract(data);
    return {
      rows: (data.data || []) as T[],
      meta: { current_page: data.current_page ?? 1, last_page: data.last_page ?? 1, total: data.total ?? 0 },
    };
  }, [data, extract]);

  return (
    <div>
      <PageHeader title={title} subtitle={subtitle} />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="relative min-w-[200px] flex-1">
          <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={searchPlaceholder}
            className="w-full rounded-xl border border-line bg-card py-2 pl-9 pr-3 text-sm text-fg outline-none focus:border-brand"
          />
        </div>
        {filters.map((f) => (
          <select
            key={f.key}
            value={filterValues[f.key] || ""}
            onChange={(e) => { setFilterValues((v) => ({ ...v, [f.key]: e.target.value })); setPage(1); }}
            className="rounded-xl border border-line bg-card px-3 py-2 text-sm text-fg outline-none focus:border-brand"
          >
            <option value="">{f.label}</option>
            {f.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        ))}
      </div>

      <Card bodyClassName="">
        {loading && !parsed ? (
          <Spinner />
        ) : error ? (
          <p className="py-6 text-center text-sm text-bad">{error}</p>
        ) : (
          <>
            <ResponsiveTable columns={cols} rows={parsed?.rows || []} rowKey={rowKey} empty={empty} />
            {parsed && parsed.meta.total > 0 && (
              <div className="mt-4 flex items-center justify-between border-t border-line pt-3 text-xs text-muted">
                <span>Page {parsed.meta.current_page} of {parsed.meta.last_page} · {parsed.meta.total} total</span>
                <span className="flex gap-2">
                  <button
                    disabled={parsed.meta.current_page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    className="rounded-lg border border-line px-3 py-1.5 font-semibold text-fg disabled:opacity-40"
                  >Prev</button>
                  <button
                    disabled={parsed.meta.current_page >= parsed.meta.last_page}
                    onClick={() => setPage((p) => p + 1)}
                    className="rounded-lg border border-line px-3 py-1.5 font-semibold text-fg disabled:opacity-40"
                  >Next</button>
                </span>
              </div>
            )}
          </>
        )}
      </Card>
    </div>
  );
}
