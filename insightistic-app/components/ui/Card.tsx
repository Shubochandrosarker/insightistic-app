import { ReactNode } from "react";
export function Card({ title, children, className = "" }: { title?: string; children: ReactNode; className?: string }) {
  return (
    <div className={`rounded-xl border border-line bg-panel p-4 ${className}`}>
      {title && <h2 className="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">{title}</h2>}
      {children}
    </div>
  );
}
