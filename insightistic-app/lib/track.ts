/**
 * Product funnel events from the SPA (spec §33) → POST /api/events.
 * The taxonomy is enforced server-side (ProductEvent::EVENTS) and props are
 * whitelisted — sending extra keys is a no-op, never an error. Never put
 * tokens, passwords or customer data in props.
 */
import { apiPost } from "./api";

const ALLOWED = new Set([
  "signup_started", "first_insight_viewed", "onboarding_started",
  "activation_achieved", "site_added", "site_imported",
]);

let seen: Set<string> | null = null;

/** Fire-once-per-session guard for events that must not repeat (e.g. page viewed). */
function once(key: string): boolean {
  if (typeof window === "undefined") return false;
  if (!seen) {
    try {
      seen = new Set(JSON.parse(sessionStorage.getItem("ins_events") ?? "[]"));
    } catch {
      seen = new Set();
    }
  }
  if (seen.has(key)) return false;
  seen.add(key);
  try {
    sessionStorage.setItem("ins_events", JSON.stringify([...seen]));
  } catch {
    /* ignore */
  }
  return true;
}

export function track(event: string, props: Record<string, string | number | boolean | null> = {}, onlyOnce = false): void {
  if (onlyOnce && !once(event + JSON.stringify(props))) return;
  apiPost("/events", { event, props }).catch(() => {
    /* analytics must never break UX */
  });
}

export { ALLOWED as TRACKABLE_EVENTS };
