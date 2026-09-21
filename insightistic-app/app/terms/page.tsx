import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";

export default function Terms() {
  return (
    <main className="bg-white">
      <Nav />
      <article className="mx-auto max-w-3xl px-6 py-12 text-slate-700">
        <h1 className="text-3xl font-bold text-slate-900">Terms of Service</h1>
        <p className="mt-4 text-sm text-slate-500">Draft — replace with counsel-reviewed terms before launch.</p>

        <h2 className="mt-8 text-lg font-semibold text-slate-900">1. Service</h2>
        <p className="mt-2">Insightistic provides analytics and reporting for connected WordPress/WooCommerce sites.</p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">2. Accounts &amp; Billing</h2>
        <p className="mt-2">Subscriptions are billed via Stripe or Paddle. Plan limits apply per the selected plan. The Angel lifetime deal and Insight Credit packs are covered in section 5 below.</p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">3. Data</h2>
        <p className="mt-2">We process business data you connect. We do not store raw payment card data. See our Privacy Policy.</p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">4. Acceptable Use</h2>
        <p className="mt-2">You agree to use the service lawfully and to connect only sites you own or are authorized to manage.</p>

        <h2 className="mt-8 text-lg font-semibold text-slate-900">5. Insightistic Angel Lifetime Deal terms</h2>
        <p className="mt-2">An Angel lifetime deal is a one-time purchase available to the first 100 completed lifetime memberships, or until the announced campaign window ends, whichever comes first. “Lifetime” means the purchased tier’s features and its published monthly allowances for as long as Insightistic operates the service; it is not a promise of every future product or premium module.</p>
        <ul className="mt-3 list-disc space-y-1.5 pl-5">
          <li>Recurring allowances are fixed and published per tier: Angel Solo ($79) — 3 sites, 10k crawl pages/month, 150 tracked keywords, 10 AI prompts/month, basic reports; Angel Studio ($149) — 10 sites, 40k crawl pages/month, 500 tracked keywords, 40 AI prompts/month, scheduled reports; Angel Agency ($249) — 30 sites, 100k crawl pages/month, 1,500 tracked keywords, 100 AI prompts/month, white-label reports, 5 team members.</li>
          <li>Allowances reset each month and do not roll over.</li>
          <li>Insight Credit packs (5,000 / 20,000 / 50,000 credits) are one-time purchases that do not expire while the service operates. Credits power AI visibility runs, AI inference and SERP checks; core data syncs never consume credits.</li>
          <li>Fair use applies to metered features — automated or abusive consumption may be throttled.</li>
          <li>For an Angel lifetime purchase, a buyer may request a 100% refund within 30 calendar days of the original purchase, no questions asked. The request must be made through the published support/contact route and include enough information to identify the transaction. The payment provider’s refund records and the published Refund &amp; Cancellation Policy control processing.</li>
          <li>Insightistic may discontinue service to accounts engaged in abusive usage, per section 4.</li>
        </ul>

        <h2 className="mt-8 text-lg font-semibold text-slate-900">6. Ranking and AI citations</h2>
        <p className="mt-2">Insightistic can help organize evidence, analytics and source-ready content. No ranking, citation, AI Overview, AI agent or traffic outcome is guaranteed because those systems are controlled by third parties and change over time.</p>
      </article>
      <Footer />
    </main>
  );
}
