import type { HTMLAttributes } from "react";

type BrandLogoProps = HTMLAttributes<HTMLSpanElement> & {
  imageClassName?: string;
};

/** The canonical Insightistic wordmark used across public and authenticated UI. */
export function BrandLogo({ className = "", imageClassName = "", ...props }: BrandLogoProps) {
  return (
    <span className={`ins-brand-logo ${className}`.trim()} {...props}>
      {/* eslint-disable-next-line @next/next/no-img-element -- static brand asset */}
      <img src="/logos/insightistic-logo.png" alt="Insightistic" className={imageClassName} />
    </span>
  );
}
