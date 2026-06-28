import Link from "next/link";
import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
import { PricingCards } from "@/components/marketing/PricingCards";

const features = [
  ["WooCommerce Analytics", "Revenue, orders, AOV, refunds — clean and current."],
  ["AI Business Insights", "Plain-language explanations of what changed and what to do."],
  ["Email Reports", "Weekly and monthly reports delivered automatically."],
  ["Product Intelligence", "Top sellers, slow movers, low stock at a glance."],
  ["Customer Insights", "New vs returning, top customers, locations."],
  ["Agency White Label", "Your logo, your colors, your client dashboards."],
  ["Branded PDF Reports", "Send polished, on-brand reports to clients."],
  ["Client Dashboard", "Give each client a read-only view of their site."],
];

export default function Home() {
  return (
    <main className="bg-white">
      <Nav />

      <section className="mx-auto max-w-6xl px-6 py-20 text-center">
        <h1 className="mx-auto max-w-3xl text-4xl font-bold leading-tight text-slate-900 md:text-5xl">
          AI Business Analytics for WordPress and WooCommerce
        </h1>
        <p className="mx-auto mt-5 max-w-2xl text-lg text-slate-600">
          Connect your site and see revenue, customers, products, emails, and AI business insights in one clean dashboard.
        </p>
        <div className="mt-8 flex justify-center gap-3">
          <Link href="/register" className="rounded-lg bg-brand px-6 py-3 font-medium text-white hover:bg-brand2">Start Free Trial</Link>
          <Link href="/pricing" className="rounded-lg border border-slate-300 px-6 py-3 font-medium text-slate-700 hover:border-slate-400">View Agency Plan</Link>
        </div>
      </section>

      <section className="bg-slate-50 py-16">
        <div className="mx-auto max-w-3xl px-6 text-center">
          <h2 className="text-2xl font-semibold text-slate-900">Your business data is scattered</h2>
          <p className="mt-3 text-slate-600">
            WordPress, WooCommerce, emails, forms, reports. Insightistic brings the important numbers together and explains what they mean — what happened, why it happened, and what to do next.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 py-16">
        <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
          {features.map(([t, d]) => (
            <div key={t} className="rounded-xl border border-slate-200 p-5">
              <h3 className="font-semibold text-slate-900">{t}</h3>
              <p className="mt-2 text-sm text-slate-600">{d}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="bg-slate-900 py-16 text-white">
        <div className="mx-auto max-w-3xl px-6 text-center">
          <h2 className="text-2xl font-semibold">Turn client reporting into a white-label product</h2>
          <p className="mt-3 text-slate-300">
            Give every client a branded analytics dashboard with clear reports, AI insights, and business performance tracking.
          </p>
          <Link href="/pricing" className="mt-6 inline-block rounded-lg bg-brand px-6 py-3 font-medium hover:bg-brand2">See Agency plan</Link>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 py-16">
        <h2 className="mb-8 text-center text-2xl font-semibold text-slate-900">Simple pricing</h2>
        <PricingCards />
      </section>

      <section className="bg-slate-50 py-16 text-center">
        <h2 className="text-2xl font-semibold text-slate-900">Stop guessing. Start seeing the business clearly.</h2>
        <Link href="/register" className="mt-6 inline-block rounded-lg bg-brand px-6 py-3 font-medium text-white hover:bg-brand2">Start Free Trial</Link>
      </section>

      <Footer />
    </main>
  );
}
