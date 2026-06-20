import { Nav } from "@/components/marketing/Nav";
import { Footer } from "@/components/marketing/Footer";
export default function Privacy() {
  return (
    <main className="bg-white">
      <Nav />
      <article className="mx-auto max-w-3xl px-6 py-12 text-slate-700">
        <h1 className="text-3xl font-bold text-slate-900">Privacy Policy</h1>
        <p className="mt-4 text-sm text-slate-500">Template — replace with counsel-reviewed policy before launch.</p>
        <h2 className="mt-8 text-lg font-semibold text-slate-900">Data we process</h2>
        <p className="mt-2">Order, product, and customer metrics synced from your connected sites. Customer emails are hashed before leaving your store; we do not receive raw payment data.</p>
        <h2 className="mt-6 text-lg font-semibold text-slate-900">How we use it</h2>
        <p className="mt-2">To produce analytics, AI insights, and reports for your organization. Data is isolated per tenant.</p>
        <h2 className="mt-6 text-lg font-semibold text-slate-900">Sub-processors</h2>
        <p className="mt-2">Stripe (billing), our AI provider (insight generation), and email delivery providers.</p>
        <h2 className="mt-6 text-lg font-semibold text-slate-900">Your choices</h2>
        <p className="mt-2">You can disconnect a site or request deletion of your organization's data at any time.</p>
      </article>
      <Footer />
    </main>
  );
}
