import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
import { PricingCards } from "@/components/marketing/PricingCards";

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
        <p className="mt-10 text-center text-sm text-slate-500">
          Lifetime deals available at launch · AI/report credits renew yearly on LTD plans.
        </p>
      </section>
      <Footer />
    </main>
  );
}
