"use client";
import Link from "next/link";
import { useApi } from "@/lib/useApi";

/**
 * Global contextual status banner (spec §10 conversion system). One quiet
 * strip above the page content; the message depends on license state:
 *   trialing (≤3 days)  amber countdown
 *   expired             violet "Reactivate"
 *   payment_failed      red + grace note
 *   cancelled           red  "Reactivate"
 *   suspended/revoked   red  support
 */
export function TrialBanner() {
  const { data } = useApi<{ licenses: any[]; trial_ends_at: string | null }>("/licenses");
  const license = data?.licenses?.[0];
  if (!license) return null;

  let tone = "";
  let text: string | null = null;
  let cta = "Choose a plan";
  let href = "/dashboard/billing";

  if (license.status === "trialing" && data?.trial_ends_at) {
    const days = Math.max(0, Math.ceil((new Date(data.trial_ends_at).getTime() - Date.now()) / 86400000));
    if (days <= 3) {
      tone = "border-warn/30 bg-warn/10 text-warn";
      text = days === 0
        ? "Your trial ends today. Keep advanced analytics active by choosing a plan."
        : `Your trial ends in ${days} day${days === 1 ? "" : "s"}. Keep your advanced analytics active.`;
    }
  } else if (license.status === "expired") {
    tone = "border-violet/30 bg-violet/10 text-violet";
    text = "Your advanced features are paused. Reactivate to continue where you left off.";
    cta = "Reactivate";
  } else if (license.status === "payment_failed") {
    tone = "border-bad/30 bg-bad/10 text-bad";
    text = "We couldn't process your payment. Your features stay active for a few days while we retry.";
    cta = "Update payment method";
  } else if (license.status === "cancelled") {
    tone = "border-bad/30 bg-bad/10 text-bad";
    text = "Your subscription has ended. Your data is safe — reactivate any time.";
    cta = "Reactivate";
  } else if (license.status === "suspended" || license.status === "revoked") {
    tone = "border-bad/30 bg-bad/10 text-bad";
    text = "There's an issue with your account access. Please update billing or contact support.";
    cta = "Go to billing";
  }

  if (!text) return null;

  return (
    <div className={`mb-5 flex flex-col gap-2 rounded-xl border px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between ${tone}`}>
      <span className="font-medium">{text}</span>
      <Link
        href={href}
        className="inline-flex w-fit shrink-0 items-center rounded-lg bg-card px-3 py-1.5 text-xs font-bold text-fg shadow-sm transition hover:opacity-80"
      >
        {cta} →
      </Link>
    </div>
  );
}
