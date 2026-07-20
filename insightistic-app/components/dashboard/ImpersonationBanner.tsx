"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { apiPost, getImpersonation, clearImpersonation, ImpersonationMeta } from "@/lib/api";
import { ShieldAlert } from "lucide-react";

/** Persistent banner + clear exit for an active impersonation session (spec §13.9). */
export function ImpersonationBanner() {
  const { applyToken } = useAuth();
  const router = useRouter();
  const [meta, setMeta] = useState<ImpersonationMeta | null>(null);
  const [exiting, setExiting] = useState(false);

  useEffect(() => {
    setMeta(getImpersonation()?.meta ?? null);
  }, []);

  if (!meta) return null;

  async function exit() {
    setExiting(true);
    const stash = getImpersonation();
    try { await apiPost("/impersonation/end"); } catch { /* still exit locally even if the server call fails */ }
    if (stash) await applyToken(stash.adminToken);
    clearImpersonation();
    router.push("/admin/users");
  }

  return (
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warn/40 bg-warn/15 px-4 py-3">
      <div className="flex items-center gap-2 text-sm text-fg">
        <ShieldAlert size={16} className="text-warn" />
        <span>
          <strong>{meta.admin_name}</strong> is viewing as <strong>{meta.target_name}</strong>
          {!meta.elevated && " (read-only)"} — {meta.reason}
        </span>
      </div>
      <button
        onClick={exit}
        disabled={exiting}
        className="rounded-lg bg-warn px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
      >
        {exiting ? "Exiting…" : "Exit impersonation"}
      </button>
    </div>
  );
}
