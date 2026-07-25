"use client";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Check, ChevronRight } from "lucide-react";

type Step = { key: string; label: string; complete: boolean; href: string | null };

type Onboarding = {
  steps: Step[];
  completed: number;
  total: number;
  finished: boolean;
  dismissed: boolean;
  next: string | null;
};

/**
 * Guided setup checklist shown on the dashboard until it is finished or
 * dismissed.
 *
 * Progress comes from the API, which derives it from real state, so this
 * component holds no cursor of its own — reloading, switching device or
 * having a teammate complete a step all just work.
 */
export function OnboardingChecklist() {
  const { data, reload } = useApi<Onboarding>("/onboarding");

  if (!data || data.dismissed || data.finished) return null;

  const pct = Math.round((data.completed / data.total) * 100);

  return (
    <section className="mb-5 rounded-2xl border border-line bg-card p-5 shadow-card">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-fg">Finish setting up Insightistic</h2>
          <p className="mt-0.5 text-xs text-muted">
            {data.completed} of {data.total} done — pick up wherever you left off.
          </p>
        </div>
        <button
          onClick={async () => {
            await apiPost("/onboarding/dismiss");
            reload();
          }}
          className="text-xs font-semibold text-muted hover:text-fg"
        >
          Dismiss
        </button>
      </div>

      <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-card2">
        <div className="h-full rounded-full bg-brand transition-all" style={{ width: `${pct}%` }} />
      </div>

      <ul className="mt-4 space-y-1">
        {data.steps.map((step) => {
          const isNext = step.key === data.next;

          const body = (
            <span
              className={`flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm transition ${
                isNext ? "bg-brand/10 font-medium text-brand-700" : step.complete ? "text-muted" : "text-fg"
              }`}
            >
              <span
                className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[10px] ${
                  step.complete ? "border-good bg-good text-white" : "border-line text-muted"
                }`}
              >
                {step.complete && <Check size={12} />}
              </span>
              <span className={step.complete ? "line-through" : ""}>{step.label}</span>
              {isNext && <ChevronRight size={14} className="ml-auto" />}
            </span>
          );

          return (
            <li key={step.key}>
              {step.href && !step.complete ? <Link href={step.href}>{body}</Link> : body}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
