import { ButtonHTMLAttributes } from "react";

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: "primary" | "ghost" | "soft" | "danger";
};

export function Button({ variant = "primary", className = "", ...rest }: Props) {
  const base =
    "inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed";
  const styles = {
    primary: "bg-brand text-white hover:bg-brand2 shadow-[0_8px_20px_-8px_rgba(0,192,75,0.55)]",
    ghost: "border border-line bg-card text-fg hover:border-brand/40 hover:bg-card2",
    soft: "bg-brand/10 text-brand-700 hover:bg-brand/15",
    danger: "text-bad hover:bg-bad/10",
  }[variant];
  return <button className={`${base} ${styles} ${className}`} {...rest} />;
}
