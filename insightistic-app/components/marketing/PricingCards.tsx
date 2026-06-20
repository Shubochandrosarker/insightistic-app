import Link from "next/link";

const plans = [
  { name: "Starter", price: "$9", sites: "1 site", feats: ["Basic dashboard", "20 AI insights/mo", "4 reports/mo"] },
  { name: "Growth", price: "$29", sites: "3 sites", feats: ["WooCommerce analytics", "100 AI insights/mo", "Weekly email reports"] },
  { name: "Business", price: "$79", sites: "10 sites", feats: ["Advanced analytics", "300 AI insights/mo", "CSV/PDF export"] },
  { name: "Agency", price: "$199", sites: "30 sites", highlight: true, feats: ["White-label dashboard", "Client viewer access", "Branded PDF reports", "1000 AI insights/mo"] },
  { name: "Agency Pro", price: "$399", sites: "100 sites", feats: ["Custom domain", "Priority support", "3000 AI insights/mo"] },
];

export function PricingCards() {
  return (
    <div className="grid gap-5 md:grid-cols-3 lg:grid-cols-5">
      {plans.map((p) => (
        <div key={p.name} className={`rounded-2xl border p-6 ${p.highlight ? "border-brand ring-2 ring-brand/30" : "border-slate-200"}`}>
          {p.highlight && <div className="mb-2 inline-block rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand">Most popular</div>}
          <h3 className="text-lg font-semibold text-slate-900">{p.name}</h3>
          <div className="mt-2 text-3xl font-bold text-slate-900">{p.price}<span className="text-base font-normal text-slate-500">/mo</span></div>
          <div className="mt-1 text-sm text-slate-500">{p.sites}</div>
          <ul className="mt-4 space-y-2 text-sm text-slate-600">
            {p.feats.map((f) => <li key={f}>• {f}</li>)}
          </ul>
          <Link href="/register" className={`mt-6 block rounded-lg py-2 text-center text-sm font-medium ${p.highlight ? "bg-brand text-white hover:bg-blue-600" : "border border-slate-300 text-slate-700 hover:border-slate-400"}`}>
            Start free trial
          </Link>
        </div>
      ))}
    </div>
  );
}
