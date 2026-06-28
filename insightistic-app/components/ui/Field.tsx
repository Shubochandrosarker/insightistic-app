import { InputHTMLAttributes } from "react";

export function Field({ label, hint, ...rest }: { label?: string; hint?: string } & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="block">
      {label && <span className="mb-1.5 block text-xs font-semibold text-fg">{label}</span>}
      <input
        className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none transition placeholder:text-muted focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/15"
        {...rest}
      />
      {hint && <span className="mt-1 block text-xs text-muted">{hint}</span>}
    </label>
  );
}
