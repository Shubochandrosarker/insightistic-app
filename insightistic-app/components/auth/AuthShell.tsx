"use client";
import Link from "next/link";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { BrandLogo } from "@/components/BrandLogo";
import { TRIAL_DAYS } from "@/lib/trial";

type Stat = { value?: number; suffix?: string; big: string; small: string };

const stats: Stat[] = [
  { value: 3, suffix: " min", big: "3 min", small: "to connect" },
  { value: TRIAL_DAYS, suffix: " days", big: `${TRIAL_DAYS} days`, small: "free trial" },
  { big: "White-label", small: "for agencies" },
];

function prefersReducedMotion() {
  return typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
}

/** Counts up to a stat once on mount; renders the final text for reduced motion / screen readers. */
function CountUp({ stat, delay }: { stat: Stat; delay: number }) {
  const [n, setN] = useState<number | null>(null);
  const raf = useRef<number>(0);

  useEffect(() => {
    if (stat.value === undefined || prefersReducedMotion()) return;
    const target = stat.value;
    let start = 0;
    setN(0);
    const tick = (t: number) => {
      if (!start) start = t + delay;
      const p = Math.min(1, Math.max(0, (t - start) / 1100));
      setN(Math.round(target * (1 - Math.pow(1 - p, 3))));
      if (p < 1) raf.current = requestAnimationFrame(tick);
    };
    raf.current = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf.current);
  }, [stat.value, delay]);

  return (
    <span aria-label={stat.big}>
      <span aria-hidden="true">{n === null ? stat.big : `${n}${stat.suffix ?? ""}`}</span>
    </span>
  );
}

/** Decorative glass cards floating over the aurora. Illustrative only — no data claims. */
function FloatingCards() {
  return (
    <div className="ins-auth-cards" aria-hidden="true">
      <div className="ins-glass ins-glass--chart">
        <div className="ins-glass__head">
          <span className="ins-glass__dot" /> Revenue trend
        </div>
        <svg viewBox="0 0 220 70" className="ins-glass__spark">
          <defs>
            <linearGradient id="ins-spark-fill" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stopColor="#00d084" stopOpacity="0.45" />
              <stop offset="100%" stopColor="#00d084" stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d="M0 58 L28 50 L56 54 L84 38 L112 42 L140 26 L168 30 L196 14 L220 10 L220 70 L0 70Z" fill="url(#ins-spark-fill)" />
          <path className="ins-glass__line" d="M0 58 L28 50 L56 54 L84 38 L112 42 L140 26 L168 30 L196 14 L220 10" />
        </svg>
      </div>

      <div className="ins-glass ins-glass--bars">
        <div className="ins-glass__head">Orders by day</div>
        <div className="ins-glass__bars">
          {[40, 62, 48, 80, 56, 92, 70].map((h, i) => (
            <span key={i} style={{ height: `${h}%`, animationDelay: `${i * 90}ms` }} />
          ))}
        </div>
      </div>

      <div className="ins-glass ins-glass--insight">
        <div className="ins-glass__spark-icon">✦</div>
        <div>
          <div className="ins-glass__head">AI insight</div>
          <p>What changed, why it matters, what to do next.</p>
        </div>
      </div>
    </div>
  );
}

/**
 * Split-screen auth layout: an animated aurora brand panel with floating
 * glass dashboard cards on the left, the form on the right. All motion is
 * CSS-driven and disabled under prefers-reduced-motion.
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
    <main className="ins-auth flex min-h-screen bg-white">
      {/* Brand panel */}
      <aside className="ins-auth-panel relative hidden w-[46%] flex-col justify-between overflow-hidden p-12 text-white lg:flex">
        <div className="ins-aurora" aria-hidden="true">
          <span className="ins-aurora__blob ins-aurora__blob--a" />
          <span className="ins-aurora__blob ins-aurora__blob--b" />
          <span className="ins-aurora__blob ins-aurora__blob--c" />
          <span className="ins-aurora__grid" />
        </div>

        <div className="ins-auth-rise relative z-10 flex items-center gap-2">
          <BrandLogo variant="inverse" size={32} />
        </div>

        <FloatingCards />

        <div className="relative z-10 max-w-md">
          <p className="ins-auth-rise text-xs font-semibold uppercase tracking-[0.22em] text-brand2" style={{ animationDelay: "80ms" }}>
            AI Business Analytics
          </p>
          <h2 className="ins-auth-rise mt-4 text-4xl font-bold leading-[1.1] tracking-tight" style={{ animationDelay: "160ms" }}>
            One clean dashboard for revenue, customers, products &amp;{" "}
            <span className="ins-auth-shimmer">AI insights.</span>
          </h2>
          <p className="ins-auth-rise mt-5 text-sm leading-relaxed text-white/60" style={{ animationDelay: "240ms" }}>
            Connect your WordPress or WooCommerce site and let AI explain what changed,
            why it matters, and what to do next.
          </p>
          <div className="mt-8 flex gap-9">
            {stats.map((s, i) => (
              <div key={s.big} className="ins-auth-rise" style={{ animationDelay: `${320 + i * 90}ms` }}>
                <div className="text-2xl font-bold tabular-nums text-brand2">
                  <CountUp stat={s} delay={400 + i * 120} />
                </div>
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
      <section className="ins-auth-form-panel relative flex w-full flex-1 items-center justify-center px-6 py-12">
        <div className="ins-auth-mobile-glow lg:hidden" aria-hidden="true" />
        <div className="ins-auth-card relative w-full max-w-md">
          <Link href="/" className="mb-8 flex items-center gap-2 lg:hidden">
            <BrandLogo size={28} />
          </Link>

          <h1 className="ins-auth-rise text-2xl font-bold tracking-tight text-ink">{title}</h1>
          {subtitle && (
            <p className="ins-auth-rise mt-1.5 text-sm text-slate-500" style={{ animationDelay: "60ms" }}>
              {subtitle}
            </p>
          )}

          <div className="ins-auth-stagger mt-7">{children}</div>

          {footer && (
            <div className="ins-auth-rise mt-6 text-center text-sm text-slate-500" style={{ animationDelay: "420ms" }}>
              {footer}
            </div>
          )}
        </div>
      </section>
    </main>
  );
}
