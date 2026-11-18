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
          Recurring plan payments are processed by Stripe. Insightistic does not store raw payment
          card details. Plan limits apply per the selected plan and are enforced server-side. AppSumo
          lifetime entitlements are redeemed through AppSumo and are not Stripe subscriptions.
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
          the end of the paid period. Refund requests for Stripe subscriptions should be sent to
          support within 14 days of purchase. AppSumo redemptions follow the applicable AppSumo
          purchase and refund terms.
        </p>

        <h2 className="mt-8 text-lg font-semibold text-slate-900">6. AppSumo lifetime entitlements</h2>
        <p className="mt-2">
          AppSumo Tier 1 through Tier 5 are one-time marketplace entitlements. &ldquo;Lifetime&rdquo; means
          the redeemed tier&rsquo;s published features and monthly allowances for as long as Insightistic
          operates the service; it is not a promise of every future product or premium module.
        </p>
        <ul className="mt-3 list-disc space-y-1.5 pl-5">
          <li>
            The current catalog has five AppSumo tiers. The exact site, crawl, browser, keyword, report,
            AI-action and credit allowances are shown in the AppSumo offer and applied from the signed
            redemption record.
          </li>
          <li>Allowances reset each month and do not roll over.</li>
          <li>Monthly included credits reset with the monthly allowance.</li>
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
