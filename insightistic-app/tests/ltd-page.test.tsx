import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";

const nav = vi.hoisted(() => ({
  redirect: vi.fn((to: string) => { throw new Error(`NEXT_REDIRECT:${to}`); }),
}));
vi.mock("next/navigation", () => ({
  redirect: nav.redirect,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/ltd",
}));
// No live catalog in tests: AngelPricing falls back to the published tiers.
vi.mock("@/lib/api", () => ({ apiGet: vi.fn(() => new Promise(() => {})) }));

import LtdPage, { generateMetadata } from "@/app/ltd/page";
import Pricing from "@/app/pricing/page";

const AFFILIATE = "https://appsumo.8odi.net/insightistic-angel";
afterEach(() => vi.unstubAllEnvs());

describe("/ltd page", () => {
  it("is hidden (redirect + noindex metadata) while APPSUMO_AFFILIATE_URL is empty", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", "");
    expect(() => LtdPage()).toThrow("NEXT_REDIRECT:/pricing");
    expect(generateMetadata().robots).toEqual({ index: false, follow: false });
  });

  it("shows the offer once APPSUMO_AFFILIATE_URL is set; every Buy Now uses it", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", AFFILIATE);
    expect(generateMetadata().robots).toBeUndefined();
    render(LtdPage());
    expect(screen.getByRole("heading", { level: 1, name: /Founding Angel lifetime deal/i })).toBeInTheDocument();
    const buttons = screen.getAllByRole("link", { name: "Buy now" });
    expect(buttons).toHaveLength(3);
    for (const b of buttons) {
      expect(b).toHaveAttribute("href", AFFILIATE);
      expect(b).toHaveAttribute("target", "_blank");
    }
    expect(screen.queryByRole("link", { name: /Create account/i })).toBeNull();
  });

  it("keeps the Founding Angel prices at $149 / $299 / $699", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", AFFILIATE);
    render(LtdPage());
    for (const [tier, price] of [["angel_solo", "$149"], ["angel_studio", "$299"], ["angel_agency", "$699"]]) {
      const card = document.querySelector(`[data-tier="${tier}"]`)!.parentElement!;
      expect(within(card).getByText(price)).toBeInTheDocument();
    }
  });
});

describe("/pricing", () => {
  it("does not show or link the LTD offer while hidden", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", "");
    render(Pricing());
    expect(screen.queryByText(/Founding Angel/i)).toBeNull();
    expect(screen.queryByRole("link", { name: "Buy now" })).toBeNull();
    expect(document.querySelector('a[href="/ltd"], a[href*="plan=angel_"]')).toBeNull();
  });

  it("links to /ltd only once the offer is live", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", AFFILIATE);
    render(Pricing());
    expect(screen.getByRole("link", { name: /Founding Angel lifetime deal/i })).toHaveAttribute("href", "/ltd");
  });
});

describe("site chrome", () => {
  it("nav and footer never link to LTD offer URLs", () => {
    for (const f of ["components/marketing/Nav.tsx", "components/marketing/Footer.tsx"]) {
      const src = readFileSync(f, "utf8");
      expect(src).not.toMatch(/\/ltd|lifetime|founding-angel|plan=angel_/i);
    }
  });
});
