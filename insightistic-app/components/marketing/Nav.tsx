import Link from "next/link";
export function Nav() {
  return (
    <header className="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
      <Link href="/" className="flex items-center gap-2 text-lg font-bold text-slate-900">
        {/* eslint-disable-next-line @next/next/no-img-element -- static brand asset */}
        <img src="/logos/insightistic-icon.svg" alt="" aria-hidden="true" className="h-8 w-8" />
        <span>Insightistic</span>
      </Link>
      <nav className="flex items-center gap-5 text-sm text-slate-600">
        <a href="https://www.insightistic.com/ltd/" className="font-medium text-blue-700 hover:text-blue-900">Angel LTD</a>
        <Link href="/pricing" className="hover:text-slate-900">Pricing</Link>
        <Link href="/login" className="hover:text-slate-900">Sign in</Link>
        <Link href="/register" className="rounded-lg bg-brand px-4 py-2 font-medium text-white hover:bg-brand2">Start free trial</Link>
      </nav>
    </header>
  );
}
