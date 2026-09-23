"use client";

import { useEffect, useState } from "react";
import { getMarketingConsent, setMarketingConsent } from "@/lib/consent";

/** Consent gate for optional advertising conversion delivery. */
export default function ConsentBanner() {
  const [choice, setChoice] = useState<boolean | null>(null);

  useEffect(() => setChoice(getMarketingConsent()), []);

  if (choice !== null) return null;

  return (
    <aside className="fixed inset-x-3 bottom-3 z-50 mx-auto max-w-xl rounded-2xl border border-line bg-card p-4 shadow-xl">
      <p className="text-sm font-semibold text-fg">Help us measure marketing performance</p>
      <p className="mt-1 text-xs leading-relaxed text-muted">
        Insightistic does not load advertising tags by default. If you allow marketing measurement,
        a verified Paddle purchase may be matched to a consenting Google or Meta ad click.
      </p>
      <div className="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          className="ins-btn px-3 py-1.5 text-xs"
          onClick={() => { setMarketingConsent(true); setChoice(true); }}
        >
          Allow marketing measurement
        </button>
        <button
          type="button"
          className="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold text-muted hover:text-fg"
          onClick={() => { setMarketingConsent(false); setChoice(false); }}
        >
          Decline
        </button>
      </div>
    </aside>
  );
}
