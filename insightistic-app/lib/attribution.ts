/**
 * Client-side marketing attribution (spec §32).
 *
 * On every page load we harvest the visit context — landing URL, referrer,
 * UTM params, ad click ids (gclid/fbclid/wbclid) — into localStorage:
 *   first_touch: set ONCE, the earliest visit we ever saw (never overwritten)
 *   last_touch : every new visit with campaign params refreshes it
 * The bundle is sent with the register call; the server whitelists every
 * key again before storage (AttributionService). No PII is captured — only
 * the page URL context the browser already has.
 */

const FIRST_KEY = "ins_attr_first";
const LAST_KEY = "ins_attr_last";

export interface Attribution {
  landing_url?: string;
  landing_path?: string;
  referrer?: string;
  utm_source?: string;
  utm_medium?: string;
  utm_campaign?: string;
  utm_content?: string;
  utm_term?: string;
  gclid?: string;
  fbclid?: string;
  wbraid?: string;
  [k: string]: string | undefined;
}

function harvest(): Attribution {
  const out: Attribution = {};
  if (typeof window === "undefined") return out;

  try {
    const q = new URLSearchParams(window.location.search);
    const keys = [
      "utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term",
      "gclid", "fbclid", "wbraid",
    ];
    for (const k of keys) {
      const v = q.get(k);
      if (v) out[k] = v;
    }
    out.landing_path = window.location.pathname;
    if (q.toString() !== "") {
      out.landing_url = window.location.origin + window.location.pathname + "?" + q.toString();
    }
    if (document.referrer) out.referrer = document.referrer;
  } catch {
    // storage/parsing failures must never break the app
  }
  return out;
}

function read(key: string): Attribution | null {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as Attribution) : null;
  } catch {
    return null;
  }
}

function write(key: string, value: Attribution) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* private mode etc. */
  }
}

/** Call once per app mount (root layout effect). */
export function captureAttribution(): void {
  if (typeof window === "undefined") return;
  const visit = harvest();

  // Only visits that actually carry campaign context refresh last-touch.
  const hasCampaign =
    visit.utm_source || visit.utm_campaign || visit.gclid || visit.fbclid || visit.wbraid;

  if (hasCampaign) {
    const first = read(FIRST_KEY);
    if (!first) write(FIRST_KEY, visit);
    write(LAST_KEY, visit);
  } else if (!read(FIRST_KEY)) {
    // First organic visit still counts as first touch.
    const minimal: Attribution = { landing_path: visit.landing_path, referrer: visit.referrer };
    if (visit.landing_url) minimal.landing_url = visit.landing_url;
    write(FIRST_KEY, minimal);
  }
}

/** The bundle to attach to the register call. */
export function getAttributionBundle(): { first_touch?: Attribution; last_touch?: Attribution } | null {
  const first = read(FIRST_KEY);
  const last = read(LAST_KEY);
  if (!first && !last) return null;
  return { first_touch: first ?? undefined, last_touch: last ?? undefined };
}
