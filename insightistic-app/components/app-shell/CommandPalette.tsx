"use client";
import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Search } from "lucide-react";
import type { NavItem } from "./DesktopSidebar";

/**
 * Cmd/Ctrl-K navigation (spec §9).
 *
 * Deliberately hand-rolled rather than pulling in a palette library: the
 * whole behaviour is a filtered list plus keyboard handling, and the app
 * already carries its own nav model to feed it.
 *
 * Matching is subsequence-based, so "ordr" finds Orders and "aic" finds AI
 * Copilot — a palette that only does substring matching is barely faster
 * than the sidebar.
 */
export function CommandPalette({ items }: { items: NavItem[] }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        setOpen((v) => !v);
      }
      if (e.key === "Escape") setOpen(false);
    }

    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  useEffect(() => {
    if (open) {
      setQuery("");
      setActive(0);
      // Focus after paint, or the input is not in the DOM yet.
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  const results = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return items;

    return items.filter((item) => subsequence(q, item.label.toLowerCase()));
  }, [items, query]);

  if (!open) return null;

  function go(index: number) {
    const target = results[index];
    if (!target) return;
    setOpen(false);
    router.push(target.href);
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-[12vh]"
      onClick={() => setOpen(false)}
      role="presentation"
    >
      <div
        className="w-full max-w-lg overflow-hidden rounded-2xl border border-line bg-card shadow-2xl"
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label="Command palette"
      >
        <div className="flex items-center gap-2 border-b border-line px-4">
          <Search size={16} className="text-muted" />
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setActive(0);
            }}
            onKeyDown={(e) => {
              if (e.key === "ArrowDown") {
                e.preventDefault();
                setActive((i) => Math.min(i + 1, results.length - 1));
              } else if (e.key === "ArrowUp") {
                e.preventDefault();
                setActive((i) => Math.max(i - 1, 0));
              } else if (e.key === "Enter") {
                e.preventDefault();
                go(active);
              }
            }}
            placeholder="Jump to…"
            aria-label="Search pages"
            className="w-full bg-transparent py-3.5 text-sm text-fg outline-none placeholder:text-muted"
          />
          <kbd className="rounded border border-line px-1.5 py-0.5 text-[10px] text-muted">esc</kbd>
        </div>

        {results.length === 0 ? (
          <p className="px-4 py-6 text-center text-sm text-muted">Nothing matches “{query}”.</p>
        ) : (
          <ul className="max-h-80 overflow-y-auto py-1.5">
            {results.map((item, i) => {
              const Icon = item.icon;
              return (
                <li key={item.href}>
                  <button
                    onMouseEnter={() => setActive(i)}
                    onClick={() => go(i)}
                    className={`flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm transition ${
                      i === active ? "bg-brand/10 text-brand-700" : "text-fg hover:bg-card2"
                    }`}
                  >
                    {Icon && <Icon size={15} />}
                    {item.label}
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}

/** True when every character of `needle` appears in order within `haystack`. */
function subsequence(needle: string, haystack: string): boolean {
  let i = 0;
  for (const char of haystack) {
    if (char === needle[i]) i++;
    if (i === needle.length) return true;
  }
  return i === needle.length;
}
