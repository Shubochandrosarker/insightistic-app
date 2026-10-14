import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
import { PricingCards } from "@/components/marketing/PricingCards";
import { AngelPricing } from "@/components/marketing/AngelPricing";

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
          <h1 className="text-3xl font-bold text-slate-900">Simple plans that scale with you</h1>
          <p className="mt-3 text-slate-600">Start free and upgrade as your business grows. Get WordPress and WooCommerce analytics, SEO intelligence, AI insights and automated reporting in one workspace.</p>
        </div>
        <PricingCards />

        <AngelPricing />

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
