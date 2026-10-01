import type { HTMLAttributes } from "react";

type BrandLogoProps = HTMLAttributes<HTMLSpanElement> & {
  /** Pixel height for the wordmark or square mark. */
  size?: number;
  /** auto swaps to the light lockup when the app theme is dark. */
  variant?: "auto" | "inverse" | "icon";
};

/** Transparent Insightistic wordmark on light surfaces, with an accessible lockup for dark surfaces. */
export function BrandLogo({ className = "", size = 32, variant = "auto", ...props }: BrandLogoProps) {
  const label = typeof props["aria-label"] === "string" ? props["aria-label"] : "Insightistic";

  return (
    <span
      className={`ins-brand-logo ${className}`.trim()}
      data-variant={variant}
      role="img"
      aria-label={label}
      {...props}
    >
      {/* eslint-disable-next-line @next/next/no-img-element -- static brand asset */}
      <img
        src="/logos/insightistic-wordmark.png"
        alt=""
        aria-hidden="true"
        className="ins-brand-logo__wordmark"
        style={{ height: size }}
      />
      <span className="ins-brand-logo__lockup" aria-hidden="true">
        {/* eslint-disable-next-line @next/next/no-img-element -- static brand asset */}
        <img
          src="/logos/insightistic-icon.png"
          alt=""
          className="ins-brand-logo__mark"
          style={{ width: size, height: size }}
        />
        {variant !== "icon" && <span className="ins-brand-logo__name">{label}</span>}
      </span>
    </span>
  );
}
