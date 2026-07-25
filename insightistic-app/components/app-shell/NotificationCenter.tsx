"use client";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { Bell } from "lucide-react";
import { useApi } from "@/lib/useApi";
import { apiPatch } from "@/lib/api";
import { timeAgo } from "@/lib/format";

type Notification = {
  id: number;
  type: string;
  title: string;
  message: string | null;
  action_url: string | null;
  status: string;
  created_at: string | null;
  read_at: string | null;
};

/**
 * Notification bell + panel (spec §9).
 *
 * The API has existed since the automations were written but had no UI at
 * all, so trial reminders, plugin-activation prompts and billing notices
 * were being recorded and never shown to anyone.
 *
 * Opening the panel does NOT mark everything read — that would silently
 * clear notices the user has not actually looked at. Reading is per-item,
 * with an explicit "mark all read" for when they mean it.
 */
export function NotificationCenter() {
  const { data, reload } = useApi<{ notifications: Notification[] }>("/notifications");
  const [open, setOpen] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);

  const notifications = (data?.notifications || []).filter((n) => n.status !== "dismissed");
  const unread = notifications.filter((n) => n.status !== "read").length;

  // Close on outside click and Escape — a panel you can't dismiss is a trap.
  useEffect(() => {
    if (!open) return;

    function onDown(e: MouseEvent) {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) setOpen(false);
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }

    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  async function mark(id: number, status: "read" | "dismissed") {
    await apiPatch(`/notifications/${id}`, { status });
    reload();
  }

  return (
    <div className="relative" ref={panelRef}>
      <button
        onClick={() => setOpen((v) => !v)}
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : "Notifications"}
        aria-expanded={open}
        className="relative inline-flex h-9 w-9 items-center justify-center rounded-xl border border-line text-muted transition hover:border-brand/40 hover:text-fg"
      >
        <Bell size={16} />
        {unread > 0 && (
          <span className="absolute -right-1 -top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-bad px-1 text-[10px] font-bold text-white">
            {unread > 9 ? "9+" : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-40 mt-2 w-80 overflow-hidden rounded-2xl border border-line bg-card shadow-xl">
          <div className="flex items-center justify-between border-b border-line px-4 py-2.5">
            <span className="text-sm font-semibold text-fg">Notifications</span>
            {unread > 0 && (
              <button
                onClick={async () => {
                  await Promise.all(
                    notifications.filter((n) => n.status !== "read").map((n) => mark(n.id, "read")),
                  );
                }}
                className="text-xs font-semibold text-brand hover:text-brand2"
              >
                Mark all read
              </button>
            )}
          </div>

          {notifications.length === 0 ? (
            <p className="px-4 py-6 text-center text-sm text-muted">You’re all caught up.</p>
          ) : (
            <ul className="max-h-96 divide-y divide-line overflow-y-auto">
              {notifications.map((n) => (
                <li key={n.id} className={n.status === "read" ? "opacity-60" : ""}>
                  <div className="px-4 py-3">
                    <div className="flex items-start justify-between gap-2">
                      <span className="text-sm font-medium text-fg">{n.title}</span>
                      {n.status !== "read" && (
                        <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-brand" aria-hidden />
                      )}
                    </div>

                    {n.message && <p className="mt-1 text-xs text-muted">{n.message}</p>}

                    <div className="mt-2 flex items-center gap-3 text-xs">
                      {n.action_url && (
                        <Link
                          href={n.action_url}
                          onClick={() => { mark(n.id, "read"); setOpen(false); }}
                          className="font-semibold text-brand hover:text-brand2"
                        >
                          Open
                        </Link>
                      )}
                      {n.status !== "read" && (
                        <button onClick={() => mark(n.id, "read")} className="text-muted hover:text-fg">
                          Mark read
                        </button>
                      )}
                      <button onClick={() => mark(n.id, "dismissed")} className="text-muted hover:text-fg">
                        Dismiss
                      </button>
                      <span className="ml-auto text-muted">
                        {n.created_at ? timeAgo(n.created_at) : ""}
                      </span>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
