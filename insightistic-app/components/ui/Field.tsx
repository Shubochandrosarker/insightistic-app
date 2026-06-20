import { InputHTMLAttributes } from "react";
export function Field({ label, ...rest }: { label: string } & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm text-slate-300">{label}</span>
      <input
        className="w-full rounded-lg border border-line bg-ink px-3 py-2 text-sm text-slate-100 outline-none focus:border-brand"
        {...rest}
      />
    </label>
  );
}
