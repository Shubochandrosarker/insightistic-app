"use client";
import { useEffect } from "react";
import Link from "next/link";
import { AlertTriangle } from "lucide-react";

/**
 * Shared body for every route-level error boundary.
 *
 * Next only catches render-time errors here, so this is the last line before
 * a blank screen. It shows the error's own message rather than a generic
 * apology — "Site not found" is actionable, "Something went wrong" is not —
 * and always offers a way out, because a boundary with no recovery is just a
 * prettier crash.
 */
export function RouteError({
  error,
  reset,
  scope = "page",
  home = "/dashboard",
}: {
  error: Error & { digest?: string };
  reset: () => void;
  scope?: string;
  home?: string;
}) {
  useEffect(() => {
    // Boundaries swallow the error from the console in production builds;
    // log it so it still reaches the browser console and any error reporter.
    console.error(`[${scope}] render failed:`, error);
  }, [error, scope]);

  return (
    <div className="mx-auto flex max-w-lg flex-col items-center px-6 py-16 text-center">
      <span className="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-bad/10 text-bad">
        <AlertTriangle size={20} />
      </span>

      <h1 className="text-lg font-semibold text-fg">This {scope} didn’t load</h1>

      <p className="mt-2 text-sm text-muted">
        {error.message || "An unexpected error stopped this page from rendering."}
      </p>

      {error.digest && (
        <p className="mt-1 font-mono text-xs text-muted">Reference: {error.digest}</p>
      )}

      <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
        <button
          onClick={reset}
          className="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand2"
        >
          Try again
        </button>
        <Link
          href={home}
          className="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-fg transition hover:border-brand/40"
        >
          Back to safety
        </Link>
      </div>
    </div>
  );
}
