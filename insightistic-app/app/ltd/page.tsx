import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
import { AngelPricing } from "@/components/marketing/AngelPricing";
import { LTD_HIDDEN_REDIRECT, appsumoAffiliateUrl } from "@/lib/ltd";

// APPSUMO_AFFILIATE_URL is read per request, so flipping it needs no rebuild.
export const dynamic = "force-dynamic";

export function generateMetadata(): Metadata {
  return appsumoAffiliateUrl()
    ? {
        title: "Founding Angel lifetime deal — Insightistic",
        description: "One payment, lifetime access to Insightistic's core platform. Only 100 Founding Angel licenses.",
      }
    : { robots: { index: false, follow: false } };
}

export default function LtdPage() {
  const buyUrl = appsumoAffiliateUrl();
  // proxy.ts already answers 302 + noindex; this guards direct renders.
  if (!buyUrl) redirect(LTD_HIDDEN_REDIRECT);

  return (
    <main className="bg-white">
      <Nav />
      <section className="mx-auto max-w-6xl px-6 py-16">
        <div className="mb-6 text-center">
          <h1 className="text-3xl font-bold text-slate-900">Founding Angel lifetime deal</h1>
        </div>
        <AngelPricing buyUrl={buyUrl} />
      </section>
      <Footer />
    </main>
  );
}
