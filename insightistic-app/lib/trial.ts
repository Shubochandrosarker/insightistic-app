/**
 * Trial length for marketing / auth copy. One place, so the site, the app
 * and the API can't drift again. The API's value
 * (`/billing` → `trial.days`, `/public/plans` → `trial_days`, both from
 * INSIGHTISTIC_TRIAL_DAYS) wins wherever the page already has it; this is
 * the build-time default for static copy. Settled: 7 days, no card.
 */
export const TRIAL_DAYS: number = (() => {
  const n = Number(process.env.NEXT_PUBLIC_TRIAL_DAYS ?? 7);
  return Number.isFinite(n) && n > 0 ? Math.round(n) : 7;
})();

export const TRIAL_CARD_REQUIRED = false;
