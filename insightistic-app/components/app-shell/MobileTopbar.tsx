"use client";
import type { ReactNode } from "react";

/** Compact sticky mobile header with brand + a controls slot (mobile only). */
export function MobileTopbar({ brand = "Insightistic", children }: { brand?: string; children?: ReactNode }) {
  return (
    <header className="sticky top-0 z-30 flex items-center gap-2 border-b border-line bg-bg/85 px-4 py-2.5 backdrop-blur lg:hidden">
      <span className="ins-logo-dot" />
      <span className="text-base font-bold tracking-tight text-fg">{brand}</span>
      <div className="ml-auto flex items-center gap-2">{children}</div>
    </header>
  );
}
