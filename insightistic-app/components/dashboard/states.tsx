import { AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { timeAgo } from "@/lib/format";

/**
 * Shared data-panel states (roadmap doc 21: loading / empty / stale / degraded
 * / error / ready). Presentational only — pages keep fetching through useApi
 * and hand the resulting state here, so a failing API can never masquerade as
 * an empty site again.
 */

/** What useApi surfaces when a request fails: a human-readable message, or null while fine. */
export type ApiStateError = string | null;

/**
 * Danger-tinted card for a failed fetch. Replaces the empty/zeros state —
 * an HTTP 500 must not look like "no data yet".
 */
export function ErrorState({
  title = "Something went wrong",
  message,
  onRetry,
}: {
  title?: string;
  message: string;
  onRetry?: () => void;
}) {
  return (
    <div className="rounded-2xl border border-bad/25 bg-bad/10 p-5">
      <div className="flex items-start gap-3">
        <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-bad/15 text-bad">
          <AlertTriangle size={16} />
        </span>
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-fg">{title}</h3>
          <p className="mt-1 break-words text-sm text-bad">{message}</p>
          {onRetry && (
            <Button variant="ghost" className="mt-3" onClick={onRetry}>
              Try again
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

/** Thin warn-tinted strip: the data shown may be stale or partial. */
export function DegradedBanner({ message }: { message: string }) {
  return (
    <div
      role="status"
      className="mb-4 rounded-xl border border-warn/25 bg-warn/10 px-4 py-2.5 text-sm text-warn"
    >
      {message}
    </div>
  );
}

/** Tiny inline pill flagging that the numbers on screen are not current. */
export function StaleBadge({ label = "Stale data" }: { label?: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-warn/15 px-2.5 py-1 text-xs font-semibold text-warn">
      <span className="h-1.5 w-1.5 rounded-full bg-current" />
      {label}
    </span>
  );
}

/** Small muted freshness chip: "label · updated X ago", or "label · never". */
export function SourceFreshness({ label, asOf }: { label: string; asOf?: string | null }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-xs text-muted">
      {label} · {asOf ? timeAgo(asOf) : "never"}
    </span>
  );
}
