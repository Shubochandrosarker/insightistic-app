import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
export default function Terms() {
  return (
    <main className="bg-white">
      <Nav />
      <article className="mx-auto max-w-3xl px-6 py-12 text-slate-700">
        <h1 className="text-3xl font-bold text-slate-900">Terms of Service</h1>
        <p className="mt-4 text-sm text-slate-500">Template — replace with counsel-reviewed terms before launch.</p>
        <h2 className="mt-8 text-lg font-semibold text-slate-900">1. Service</h2>
        <p className="mt-2">Insightistic provides analytics and reporting for connected WordPress/WooCommerce sites.</p>
        <h2 className="mt-6 text-lg font-semibold text-slate-900">2. Accounts &amp; Billing</h2>
        <p className="mt-2">Subscriptions are billed via Stripe. Plan limits apply per the selected plan. Lifetime plans include one year of AI/report credits.</p>
        <h2 className="mt-6 text-lg font-semibold text-slate-900">3. Data</h2>
        <p className="mt-2">We process business data you connect. We do not store raw payment card data. See our Privacy Policy.</p>
        <h2 className="mt-6 text-lg font-semibold text-slate-900">4. Acceptable Use</h2>
        <p className="mt-2">You agree to use the service lawfully and to connect only sites you own or are authorized to manage.</p>
      </article>
      <Footer />
    </main>
  );
}
