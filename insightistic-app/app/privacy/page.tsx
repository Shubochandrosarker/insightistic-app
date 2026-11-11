import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
export default function Privacy() {
  return (
    <main className="bg-white">
      <Nav />
      <article className="mx-auto max-w-3xl px-6 py-12 text-slate-700">
        <h1 className="text-3xl font-bold text-slate-900">Privacy Policy</h1>
        <p className="mt-4 text-sm text-slate-500">Last updated: 23 September 2026.</p>

        <h2 className="mt-8 text-lg font-semibold text-slate-900">Data we process</h2>
        <p className="mt-2">
          Insightistic works with any website, so what we process depends on what you connect:
        </p>
        <ul className="mt-2 list-disc space-y-1.5 pl-5">
          <li>
            <strong>Website data</strong> — pages, technical SEO signals and content our crawler
            observes on sites you connect (public data, fetched like a search engine crawler).
          </li>
          <li>
            <strong>Connected integrations</strong> — search performance (Google Search Console,
            Bing Webmaster), traffic (Google Analytics 4) and edge analytics (Cloudflare), pulled
            with read-only authorization.
          </li>
          <li>
            <strong>Optional platform data</strong> — when you install the WordPress plugin or
            connect Shopify: commerce metrics (orders, products, customers). Customer emails are
            hashed before leaving your store; we never receive raw payment data.
          </li>
          <li>
            <strong>Account data</strong> — name, email, organization membership and authentication.
          </li>
        </ul>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">Authorization tokens</h2>
        <p className="mt-2">
          OAuth tokens (Google, Microsoft/Bing) and API keys/tokens you connect are stored
          encrypted at rest, are never exposed in any API response or log, and are revoked when
          you disconnect the integration.
        </p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">How we use it</h2>
        <p className="mt-2">
          To produce analytics, audits, AI visibility monitoring, insights and reports for your
          organization. Data is isolated per tenant organization. We do not sell your data.
        </p>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">Sub-processors</h2>
        <ul className="mt-2 list-disc space-y-1.5 pl-5">
          <li>Paddle or Stripe (billing; receives buyer contact and payment data required to process the purchase)</li>
          <li>Our AI provider (insight and AI-visibility generation)</li>
          <li>Email delivery providers</li>
          <li>Cloud hosting infrastructure</li>
        </ul>

        <h2 className="mt-6 text-lg font-semibold text-slate-900">Your choices</h2>
        <p className="mt-2">
          You can disconnect any site or integration, export your organization&rsquo;s data, or
          request deletion of your organization from Settings → Privacy at any time.
        </p>
      </article>
      <Footer />
    </main>
  );
}
