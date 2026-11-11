"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { apiGet } from "@/lib/api";

const FALLBACK = [
  { slug: "angel_solo", name: "Angel Solo", price: 149, sites: 3, credits: 3000 },
  { slug: "angel_studio", name: "Angel Studio", price: 299, sites: 7, credits: 8000 },
  { slug: "angel_agency", name: "Angel Agency", price: 699, sites: 15, credits: 20000 },
];

/** Founding Angel tiers. Every Buy Now button goes to the one APPSUMO_AFFILIATE_URL. */
export function AngelPricing({ buyUrl }: { buyUrl: string }) {
  const [catalog, setCatalog] = useState<any>(null);
  useEffect(() => { apiGet("/public/plans").then(setCatalog).catch(() => undefined); }, []);
  const plans = Array.isArray(catalog?.ltd?.plans) && catalog.ltd.plans.length ? catalog.ltd.plans : FALLBACK;
  const soldOut = catalog?.ltd?.campaign?.sold_out === true;

  return (
    <div className="mt-10 rounded-2xl border border-slate-200 bg-slate-50 p-6">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold text-slate-900">One payment. Lifetime access. Only 100 Founding Angels.</h2>
        <span className="text-xs text-slate-500">Monthly allowances renew · purchased credits do not expire</span>
      </div>
      <p className="mt-2 text-sm text-slate-600">Get lifetime access to Insightistic&apos;s core platform with monthly allowances that renew every month. There is no free trial, and every LTD purchase includes a 30-day money-back guarantee.</p>
      {soldOut && <p className="mt-4 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">Founding Angel Plans — Sold Out. Our first 100 licenses have been claimed.</p>}
      <div className="mt-5 grid gap-4 sm:grid-cols-3">
        {plans.map((t: any) => (
          <div key={t.slug} className="rounded-xl border border-slate-200 bg-white p-5">
            <div className="flex items-baseline justify-between"><span className="text-sm font-semibold text-slate-900">{t.name}</span><span className="text-xl font-bold text-slate-900">${t.price}</span></div>
            <p className="mt-2 text-xs leading-relaxed text-slate-500">{t.sites} sites · {Number(t.monthly_ai_credits ?? t.credits).toLocaleString()} monthly credits · shared allowances</p>
            <a aria-disabled={soldOut} data-tier={t.slug} className={`mt-4 block rounded-lg px-3 py-2 text-center text-sm font-medium ${soldOut ? "pointer-events-none bg-slate-200 text-slate-500" : "bg-slate-900 text-white hover:bg-slate-700"}`} href={buyUrl} target="_blank" rel="noopener noreferrer sponsored">{soldOut ? "Sold out" : "Buy now"}</a>
          </div>
        ))}
      </div>
      <p className="mt-4 text-xs text-slate-500">Lifetime access applies to the purchased core plan and feature family while Insightistic operates. Monthly allowances are shared across connected websites and do not roll over. <Link href="/terms" className="underline hover:text-slate-700">Deal terms</Link></p>
    </div>
  );
}
