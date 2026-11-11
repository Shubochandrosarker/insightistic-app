// @vitest-environment node
import { describe, it, expect, afterEach, vi } from "vitest";
import { NextRequest } from "next/server";
import { proxy, config } from "@/proxy";

const AFFILIATE = "https://appsumo.8odi.net/insightistic-angel";
const req = (path: string) => new NextRequest(new URL(path, "https://app.insightistic.com"));

afterEach(() => vi.unstubAllEnvs());

const HIDDEN_URLS = [
  "/ltd",
  "/ltd/angel-solo",
  "/lifetime",
  "/lifetime/anything",
  "/lifetime-deal",
  "/founding-angel",
  "/founding-angels",
  "/register?plan=angel_solo",
  "/register?plan=angel_studio",
  "/register?plan=angel_agency",
  "/register?plan=founder_tier_2",
  "/dashboard/billing?plan=angel_solo",
  "/dashboard/billing?plan=founder_tier_3&interval=monthly",
];

describe("LTD offer hidden (APPSUMO_AFFILIATE_URL empty)", () => {
  it.each(HIDDEN_URLS)("%s → 302 /pricing with noindex (never 301)", (path) => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", "");
    const res = proxy(req(path));
    expect(res.status).toBe(302);
    expect(res.headers.get("location")).toBe("https://app.insightistic.com/pricing");
    expect(res.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  });

  it("treats an unset or non-http value as hidden", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", "javascript:alert(1)");
    expect(proxy(req("/ltd")).status).toBe(302);
    vi.stubEnv("APPSUMO_AFFILIATE_URL", "   ");
    expect(proxy(req("/ltd")).status).toBe(302);
  });

  it.each([
    "/register",
    "/register?plan=growth&interval=yearly",
    "/dashboard/billing",
    "/dashboard/billing?plan=growth",
    // Founding Angel payment returns must reach the billing page (holders + in-flight payments).
    "/dashboard/billing?checkout=success&provider=stripe&plan=angel_solo&kind=lifetime",
    "/dashboard/billing?checkout=cancel&provider=stripe&plan=angel_studio&kind=lifetime",
  ])("%s passes through", (path) => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", "");
    const res = proxy(req(path));
    expect(res.status).toBe(200);
    expect(res.headers.get("location")).toBeNull();
    expect(res.headers.get("x-middleware-next")).toBe("1");
  });
});

describe("LTD offer live (APPSUMO_AFFILIATE_URL set)", () => {
  it("lets /ltd render, without noindex", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", AFFILIATE);
    const res = proxy(req("/ltd"));
    expect(res.headers.get("location")).toBeNull();
    expect(res.headers.get("x-robots-tag")).toBeNull();
    expect(res.headers.get("x-middleware-next")).toBe("1");
  });

  it.each(["/ltd/angel-solo", "/lifetime", "/lifetime-deal", "/founding-angel"])("%s → 302 /ltd", (path) => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", AFFILIATE);
    const res = proxy(req(path));
    expect(res.status).toBe(302);
    expect(res.headers.get("location")).toBe("https://app.insightistic.com/ltd");
  });

  it("keeps the legacy register entry working", () => {
    vi.stubEnv("APPSUMO_AFFILIATE_URL", AFFILIATE);
    expect(proxy(req("/register?plan=angel_solo")).headers.get("x-middleware-next")).toBe("1");
  });
});

it("runs on every LTD entry point", () => {
  for (const m of ["/ltd", "/ltd/:path*", "/lifetime", "/lifetime-deal", "/founding-angel", "/register", "/dashboard/billing"]) {
    expect(config.matcher).toContain(m);
  }
});
