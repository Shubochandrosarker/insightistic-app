import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
import { PricingCards } from "@/components/marketing/PricingCards";
import Link from "next/link";

// Founder LTD teaser (roadmap doc 24). Static copy — the launch cap is
// communicated without fake scarcity counters.
const founderTiers = [
  { name: "Founder Tier 1", price: 79, limits: "3 sites · 10k crawl pages/mo · 150 keywords · 10 AI prompts/mo · basic reports" },
  { name: "Founder Tier 2", price: 149, limits: "10 sites · 40k crawl pages/mo · 500 keywords · 40 AI prompts/mo · scheduled reports" },
  { name: "Founder Tier 3", price: 249, limits: "30 sites · 100k crawl pages/mo · 1,500 keywords · 100 AI prompts/mo · white-label reports · 5 team members" },
];

// Sibling products in the WordPressistic ecosystem. Verifyistic has no
// logo asset yet — add it to public/logos + this list when one lands.
const ecosystem = [
  {
    name: "WordPressistic",
    logo: "/logos/wordpressistic-logo.png",
    href: "https://wordpressistic.com",
    blurb: "The WordPress product studio behind Insightistic.",
  },
  {
    name: "ChatBotistic",
    logo: "/logos/chatbotistic-icon.png",
    href: "https://chatbotistic.com",
    blurb: "WhatsApp CRM, AI agents and broadcasts.",
  },
  {
    name: "Tripistic",
    logo: "/logos/tripistic-icon.svg",
    href: "https://tripistic.com",
    blurb: "Direct-booking platform for tour operators.",
  },
];

export default function Pricing() {
  return (
    <main className="bg-white">
      <Nav />
      <section className="mx-auto max-w-6xl px-6 py-16">
        <div className="mb-10 text-center">
          <h1 className="text-3xl font-bold text-slate-900">Pricing</h1>
          <p className="mt-3 text-slate-600">14-day free trial. No card required to start.</p>
        </div>
        <PricingCards />

        <div className="mt-10 rounded-2xl border border-slate-200 bg-slate-50 p-6">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="text-lg font-semibold text-slate-900">Founder lifetime deals — first 500 customers</h2>
            <span className="text-xs text-slate-500">One-time payment · allowances reset monthly</span>
          </div>
          <div className="mt-5 grid gap-4 sm:grid-cols-3">
            {founderTiers.map((t) => (
              <div key={t.name} className="rounded-xl border border-slate-200 bg-white p-5">
                <div className="flex items-baseline justify-between">
                  <span className="text-sm font-semibold text-slate-900">{t.name}</span>
                  <span className="text-xl font-bold text-slate-900">${t.price}</span>
                </div>
                <p className="mt-2 text-xs leading-relaxed text-slate-500">{t.limits}</p>
              </div>
            ))}
          </div>
          <p className="mt-4 text-xs text-slate-500">
            Lifetime = the tier&rsquo;s features for as long as Insightistic operates, with the published monthly
            allowances. No surprise meters. <Link href="/terms" className="underline hover:text-slate-700">Deal terms</Link>
          </p>
          <div className="mt-4">
            <Link
              href="/register?plan=founder_tier_2"
              className="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-slate-700"
            >
              Claim founder pricing
            </Link>
          </div>
        </div>

        <p className="mt-10 text-center text-sm text-slate-500">
          Lifetime deals available at launch · Insight Credit packs are one-time top-ups that don&rsquo;t expire.
        </p>

        <div className="mt-16 border-t border-slate-200 pt-12">
          <h2 className="text-center text-lg font-semibold text-slate-900">
            Part of the WordPressistic ecosystem
          </h2>
          <div className="mt-8 grid gap-6 sm:grid-cols-3">
            {ecosystem.map((p) => (
              <a
                key={p.name}
                href={p.href}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-4 rounded-2xl border border-slate-200 p-5 transition-colors hover:border-slate-400"
              >
                {/* eslint-disable-next-line @next/next/no-img-element -- static brand assets */}
                <img src={p.logo} alt={`${p.name} logo`} className="h-12 w-12 shrink-0 object-contain" />
                <span>
                  <span className="block text-sm font-semibold text-slate-900">{p.name}</span>
                  <span className="mt-0.5 block text-xs text-slate-500">{p.blurb}</span>
                </span>
              </a>
            ))}
          </div>
        </div>
      </section>
      <Footer />
    </main>
  );
}
