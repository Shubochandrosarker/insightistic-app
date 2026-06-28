import { ReactNode } from "react";

type Tone = "green" | "amber" | "red" | "violet" | "slate";

// Tones use the fixed hex brand colors (which support /opacity) plus a neutral
// slate that works in both light and dark themes.
const TONES: Record<Tone, string> = {
  green: "bg-good/15 text-good",
  amber: "bg-warn/15 text-warn",
  red: "bg-bad/15 text-bad",
  violet: "bg-violet/15 text-violet",
  slate: "bg-black/5 text-muted dark:bg-white/10",
};

export function Badge({ tone = "slate", children, dot = false, className = "" }:
  { tone?: Tone; children: ReactNode; dot?: boolean; className?: string }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${TONES[tone]} ${className}`}>
      {dot && <span className="h-1.5 w-1.5 rounded-full bg-current" />}
      {children}
    </span>
  );
}
