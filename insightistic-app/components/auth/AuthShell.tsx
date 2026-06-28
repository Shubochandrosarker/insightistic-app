"use client";
import Link from "next/link";
import type { ReactNode } from "react";

const stats = [
  { big: "3 min", small: "to connect" },
  { big: "14 days", small: "free trial" },
  { big: "White-label", small: "for agencies" },
];

/**
 * Split-screen auth layout matching the Insightistic SaaS design:
 * a dark brand panel on the left, a clean white form card on the right.
 */
export function AuthShell({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="flex min-h-screen bg-white">
      {/* Brand panel */}
      <aside className="relative hidden w-[44%] flex-col justify-between overflow-hidden p-12 text-white lg:flex">
        <div
          className="absolute inset-0"
          style={{
            background:
              "radial-gradient(120% 90% at 0% 0%, #0c3b27 0%, #0a1f18 42%, #0a1411 70%, #0d1326 100%)",
          }}
        />
        <div className="relative z-10 flex items-center gap-2">
          <span className="ins-logo-dot" />
          <span className="text-lg font-bold tracking-tight">Insightistic</span>
        </div>

        <div className="relative z-10 max-w-md">
          <p className="text-xs font-semibold uppercase tracking-[0.22em] text-brand2">
            AI Business Analytics
          </p>
          <h2 className="mt-4 text-4xl font-bold leading-[1.1] tracking-tight">
            One clean dashboard for revenue, customers, products &amp; AI insights.
          </h2>
          <p className="mt-5 text-sm leading-relaxed text-white/60">
            Connect your WordPress or WooCommerce site and let AI explain what changed,
            why it matters, and what to do next.
          </p>
          <div className="mt-8 flex gap-9">
            {stats.map((s) => (
              <div key={s.big}>
                <div className="text-2xl font-bold text-brand2">{s.big}</div>
                <div className="text-xs text-white/50">{s.small}</div>
              </div>
            ))}
          </div>
        </div>

        <p className="relative z-10 text-xs text-white/40">
          © {new Date().getFullYear()} Insightistic · by Wordpressistic
        </p>
      </aside>

      {/* Form panel */}
      <section className="flex w-full flex-1 items-center justify-center px-6 py-12">
        <div className="w-full max-w-md">
          <Link href="/" className="mb-8 flex items-center gap-2 lg:hidden">
            <span className="ins-logo-dot" />
            <span className="text-lg font-bold text-ink">Insightistic</span>
          </Link>

          <h1 className="text-2xl font-bold tracking-tight text-ink">{title}</h1>
          {subtitle && <p className="mt-1.5 text-sm text-slate-500">{subtitle}</p>}

          <div className="mt-7">{children}</div>

          {footer && <div className="mt-6 text-center text-sm text-slate-500">{footer}</div>}
        </div>
      </section>
    </main>
  );
}
