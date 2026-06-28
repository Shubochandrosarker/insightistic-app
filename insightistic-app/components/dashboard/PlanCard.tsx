"use client";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { num } from "@/lib/format";

/**
 * Plan + usage card pinned to the bottom of the sidebar. Best-effort: the
 * billing endpoint is owner/admin-only, so non-privileged users just get the
 * upgrade CTA without usage numbers.
 */
export function PlanCard() {
  const { data } = useApi<any>("/billing/subscription");

  const planName: string = data?.plan?.name || "Free trial";
  const used = data?.usage?.ai_insights?.used ?? null;
  const limit = data?.usage?.ai_insights?.limit ?? null;
  const ratio = used != null && limit ? Math.min(100, Math.round((used / limit) * 100)) : null;

  return (
    <div className="rounded-2xl border border-line bg-gradient-to-b from-brand/10 to-brand/[0.03] p-4">
      <div className="text-[11px] font-bold uppercase tracking-wide text-brand-700">
        {planName} plan
      </div>

      {ratio != null ? (
        <>
          <div className="mt-2 text-xs text-muted">
            {num(used)} / {num(limit)} AI insights used
          </div>
          <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
            <div className="h-full rounded-full bg-brand" style={{ width: `${ratio}%` }} />
          </div>
        </>
      ) : (
        <div className="mt-2 text-xs text-muted">Manage your subscription and usage.</div>
      )}

      <Link
        href="/dashboard/billing"
        className="mt-3 block rounded-xl bg-brand py-2 text-center text-xs font-semibold text-white hover:bg-brand2"
      >
        Upgrade plan
      </Link>
    </div>
  );
}
