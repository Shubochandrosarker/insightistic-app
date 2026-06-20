import Link from "next/link";
export function Footer() {
  return (
    <footer className="mx-auto max-w-6xl px-6 py-12 text-sm text-slate-500">
      <div className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-8">
        <div>© {new Date().getFullYear()} Insightistic · WordPressistic</div>
        <div className="flex gap-5">
          <Link href="/pricing" className="hover:text-slate-900">Pricing</Link>
          <Link href="/terms" className="hover:text-slate-900">Terms</Link>
          <Link href="/privacy" className="hover:text-slate-900">Privacy</Link>
        </div>
      </div>
    </footer>
  );
}
