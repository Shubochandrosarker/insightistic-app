import { NextResponse, type NextRequest } from "next/server";
import {
  LTD_HIDDEN_REDIRECT,
  LTD_NOINDEX,
  appsumoAffiliateUrl,
  isLtdOfferPath,
  isLtdPlan,
} from "@/lib/ltd";

/**
 * Hides the Founding Angel LTD sales entry points while APPSUMO_AFFILIATE_URL
 * is empty (see lib/ltd.ts). Always 302: the page comes back as soon as the
 * value is set, so nothing may be cached as permanent.
 */
export function proxy(request: NextRequest) {
  const { pathname, searchParams } = request.nextUrl;
  const offerPath = isLtdOfferPath(pathname);

  if (appsumoAffiliateUrl()) {
    // Offer visible: aliases (/lifetime, /ltd/x, ...) land on the one page.
    if (offerPath && pathname !== "/ltd") return temporaryRedirect(request, "/ltd", false);
    return NextResponse.next();
  }

  const plan = searchParams.get("plan");
  const hidden =
    offerPath ||
    (pathname === "/register" && isLtdPlan(plan)) ||
    // A new LTD purchase started from the app. Payment returns
    // (?checkout=success|cancel) and holders' plain billing page pass through.
    (pathname === "/dashboard/billing" && isLtdPlan(plan) && !searchParams.has("checkout"));

  return hidden ? temporaryRedirect(request, LTD_HIDDEN_REDIRECT, true) : NextResponse.next();
}

function temporaryRedirect(request: NextRequest, to: string, noindex: boolean) {
  const response = NextResponse.redirect(new URL(to, request.url), 302);
  if (noindex) response.headers.set("X-Robots-Tag", LTD_NOINDEX);
  return response;
}

export const config = {
  matcher: [
    "/ltd",
    "/ltd/:path*",
    "/lifetime",
    "/lifetime/:path*",
    "/lifetime-deal",
    "/lifetime-deal/:path*",
    "/founding-angel",
    "/founding-angel/:path*",
    "/founding-angels",
    "/founding-angels/:path*",
    "/register",
    "/dashboard/billing",
  ],
};
