"use client";

import type { ReactNode } from "react";
import { X } from "lucide-react";

export function AdminDialog({
  open,
  title,
  description,
  onClose,
  children,
}: {
  open: boolean;
  title: string;
  description?: string;
  onClose: () => void;
  children: ReactNode;
}) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#14171c]/45 p-4 pt-16 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label={title}>
      <div className="w-full max-w-2xl rounded-2xl border border-line bg-card p-5 shadow-[0_24px_70px_-30px_rgba(20,23,28,0.6)] sm:p-6">
        <div className="mb-5 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-lg font-extrabold tracking-tight text-fg">{title}</h2>
            {description && <p className="mt-1 text-sm text-muted">{description}</p>}
          </div>
          <button onClick={onClose} aria-label="Close dialog" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-line text-muted hover:bg-card2 hover:text-fg"><X size={17} /></button>
        </div>
        {children}
      </div>
    </div>
  );
}
