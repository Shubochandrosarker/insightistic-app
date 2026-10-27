import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
export default function Terms() {
  return (
    <main className="bg-white">
      <Nav />
      <article className="mx-auto max-w-3xl px-6 py-12 text-slate-700">
        <h1 className="text-3xl font-bold text-slate-900">Terms of Service</h1>
        <p className="mt-4 text-sm text-slate-500">Last updated: 23 September 2026.</p>

        <h2 className="mt-8 text-lg font-semibold text-slate-900">1. The Service</h2>
        <p className="mt-2">
          Insightistic provides search intelligence, AI visibility monitoring, website auditing,
          performance and uptime monitoring, and analytics for websites you connect. Any publicly
          accessible website is supported — including WordPress, WooCommerce, Shopify, Laravel,
          Webflow, Wix, Squarespace, static and custom-built sites. Optional connectors (such as
          the WordPress plugin) add deeper platform data but are never required for the core
          service.
        </p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">2. Accounts &amp; Billing</h2>
        <p className="mt-2">
          Payments are processed by Paddle.com as Merchant of Record. Paddle is the seller of
          record for your purchase — taxes are collected and remitted by Paddle, and your payment
          details are handled by Paddle, never by Insightistic. Plan limits apply per the selected
          plan and are enforced server-side. Founding Angel lifetime plans and Insight Credit packs are
          covered in section 5.
        </p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">3. Your Data</h2>
        <p className="mt-2">
          We process the data you or your connected integrations provide (search performance,
          traffic, site content, and optional commerce data). We do not store raw payment card
          data. Connecting a third-party source (Google, Microsoft/Bing, Cloudflare, Shopify)
          uses that provider&rsquo;s own authorization flow with read-only scopes wherever
          available. See our Privacy Policy.
        </p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">4. Acceptable Use</h2>
        <p className="mt-2">
          You agree to use the service lawfully and to connect only websites you own or are
          authorized to manage. Automated or abusive consumption of metered features (crawling,
          rank checks, AI visibility runs) may be throttled.
        </p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">5. Refunds &amp; Cancellation</h2>
        <p className="mt-2">
          Subscriptions can be cancelled at any time from your billing page; access continues to
          the end of the paid period. Every regular plan starts with a 7-day free trial and no
          payment details are required to start it. Every purchase — subscriptions, Founding Angel
          lifetime plans and credit packs — is covered by a 30-day money-back guarantee, no
          questions asked: contact support or use the Paddle receipt within 30 days of payment.
        </p>

        <h2 className="mt-8 text-lg font-semibold text-slate-900">6. Insightistic Founding Angel lifetime terms</h2>
        <p className="mt-2">
          A Founding Angel plan is a one-time purchase. &ldquo;Lifetime&rdquo; means the purchased tier&rsquo;s
          features and its published monthly allowances, for as long as Insightistic operates the service — it is
          not a promise of every future product or premium module.
        </p>
        <ul className="mt-3 list-disc space-y-1.5 pl-5">
          <li>
            There are three published tiers: Angel Solo ($149) — 3 sites and 3,000 monthly Insight Credits;
            Angel Studio ($299) — 7 sites and 8,000 monthly Insight Credits; and Angel Agency ($699) — 15 sites
            and 20,000 monthly Insight Credits. Each tier also has its published crawl, browser, keyword, team,
            report and standard-request allowances.
          </li>
          <li>Only 100 qualifying Angel licenses may be sold across all three tiers combined. Sale closure is permanent.</li>
          <li>Allowances reset each month and do not roll over.</li>
          <li>Monthly included credits expire at reset; purchased credits are separate, non-expiring credits.</li>
          <li>
            Insight Credit packs (5,000 / 20,000 / 50,000 credits) are one-time purchases that do not expire while
            the service operates. Credits power AI visibility runs beyond your plan&rsquo;s monthly allowance and
            other metered variable-cost features; core data syncs never consume credits.
          </li>
          <li>Fair use applies to metered features — automated or abusive consumption may be throttled.</li>
          <li>Insightistic may discontinue service to accounts engaged in abusive usage, per section 4.</li>
        </ul>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">7. No Ranking Guarantees</h2>
        <p className="mt-2">
          Insightistic reports data and surfaces opportunities. We do not guarantee search
          rankings, traffic, conversions, or mentions in AI-generated answers, and no such
          guarantee is offered anywhere in our marketing.
        </p>
      </article>
      <Footer />
    </main>
  );
}
