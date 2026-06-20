import { ButtonHTMLAttributes } from "react";
type Props = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "ghost" | "danger" };
export function Button({ variant = "primary", className = "", ...rest }: Props) {
  const base = "inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition disabled:opacity-50";
  const styles = {
    primary: "bg-brand text-white hover:bg-blue-600",
    ghost: "border border-line text-slate-200 hover:border-brand",
    danger: "text-red-400 hover:text-red-300",
  }[variant];
  return <button className={`${base} ${styles} ${className}`} {...rest} />;
}
