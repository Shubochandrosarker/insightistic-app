import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, act } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(async () => ({ sites: [] })),
}));

vi.mock("@/lib/api", () => apiMocks);

import { NAV_GROUPS, FLAT_ROUTES } from "@/lib/nav";
import { DashboardProvider, useDashboard } from "@/lib/dashboard";

/**
 * Phase 3 exit criterion: the grouped IA must keep every page that exists
 * today reachable. This list is deliberately hardcoded — if someone adds a
 * dashboard route and forgets the nav, this test fails; if someone deletes a
 * route, this test tells them to prune the list.
 */
const CURRENT_ROUTES = [
  "/dashboard",
  "/dashboard/insights",
  "/dashboard/intelligence",
  "/dashboard/copilot",
  "/dashboard/knowledge",
  "/dashboard/traffic",
  "/dashboard/seo",
  "/dashboard/seo/queries",
  "/dashboard/seo/pages",
  "/dashboard/seo/opportunities",
  "/dashboard/indexnow",
  "/dashboard/rank",
  "/dashboard/visibility",
  "/dashboard/audit",
  "/dashboard/audit/pages",
  "/dashboard/audit/links",
  "/dashboard/audit/issues",
  "/dashboard/audit/changes",
  "/dashboard/performance",
  "/dashboard/cloudflare",
  "/dashboard/uptime",
  "/dashboard/revenue",
  "/dashboard/products",
  "/dashboard/customers",
  "/dashboard/orders",
  "/dashboard/emails",
  "/dashboard/alerts",
  "/dashboard/reports",
  "/dashboard/site-health",
  "/dashboard/integrations",
  "/dashboard/licenses",
  "/dashboard/branding",
  "/dashboard/team",
  "/dashboard/billing",
  "/dashboard/security",
  "/dashboard/privacy",
  "/dashboard/settings",
];

describe("Nav IA", () => {
  it("covers every existing dashboard route", () => {
    for (const route of CURRENT_ROUTES) {
      expect(FLAT_ROUTES, `${route} must be reachable from the nav`).toContain(route);
    }
  });

  it("has no duplicate destinations", () => {
    expect(new Set(FLAT_ROUTES).size).toBe(FLAT_ROUTES.length);
  });

  it("never links a soon item — a nav entry that 404s is worse than none", () => {
    for (const group of NAV_GROUPS) {
      for (const item of group.items) {
        if (item.soon) expect(item.href, `"${item.label}" is marked soon but has an href`).toBeUndefined();
        if (item.href) expect(item.soon, `"${item.label}" has an href but is marked soon`).toBeFalsy();
      }
    }
  });
});

/** Renders the context values under test into the DOM. */
function RangeProbe() {
  const { period, rangeQuery, setCustomRange, setPeriod } = useDashboard();
  return (
    <div>
      <span data-testid="period">{period}</span>
      <span data-testid="range">{rangeQuery}</span>
      <button onClick={() => setCustomRange("2026-09-01", "2026-09-15")}>set-custom</button>
      <button onClick={() => setCustomRange("2026-09-15", "2026-09-01")}>set-inverted</button>
      <button onClick={() => setPeriod("last_7_days")}>set-preset</button>
    </div>
  );
}

function renderProbe() {
  return render(
    <DashboardProvider>
      <RangeProbe />
    </DashboardProvider>,
  );
}

/** Lets the provider's async site load settle so act() stays quiet. */
async function flushProvider() {
  await act(async () => {});
}

describe("Period + custom range contract", () => {
  beforeEach(() => {
    localStorage.clear();
    vi.clearAllMocks();
  });

  it("defaults to a preset period with an empty range query", async () => {
    renderProbe();
    await flushProvider();
    expect(screen.getByTestId("period")).toHaveTextContent("last_30_days");
    expect(screen.getByTestId("range")).toHaveTextContent("");
  });

  it("flips period to custom and builds the from/to query", async () => {
    const user = userEvent.setup();
    renderProbe();

    await user.click(screen.getByText("set-custom"));

    expect(screen.getByTestId("period")).toHaveTextContent("custom");
    expect(screen.getByTestId("range")).toHaveTextContent("&from=2026-09-01&to=2026-09-15");
    // Persisted as JSON so a reload restores the exact range.
    expect(JSON.parse(localStorage.getItem("ins_period") || "")).toEqual({
      key: "custom",
      from: "2026-09-01",
      to: "2026-09-15",
    });
  });

  it("choosing a preset resets the range query to empty", async () => {
    const user = userEvent.setup();
    renderProbe();

    await user.click(screen.getByText("set-custom"));
    expect(screen.getByTestId("range")).toHaveTextContent("&from=2026-09-01&to=2026-09-15");

    await user.click(screen.getByText("set-preset"));

    expect(screen.getByTestId("period")).toHaveTextContent("last_7_days");
    expect(screen.getByTestId("range")).toHaveTextContent("");
  });

  it("ignores an inverted range instead of coercing it", async () => {
    const user = userEvent.setup();
    renderProbe();

    await user.click(screen.getByText("set-inverted"));

    expect(screen.getByTestId("period")).toHaveTextContent("last_30_days");
    expect(screen.getByTestId("range")).toHaveTextContent("");
  });

  it("still reads the legacy plain-string ins_period format", async () => {
    localStorage.setItem("ins_period", "last_7_days");
    renderProbe();
    await flushProvider();

    expect(screen.getByTestId("period")).toHaveTextContent("last_7_days");
    expect(screen.getByTestId("range")).toHaveTextContent("");
  });

  it("restores a persisted custom range across a reload", async () => {
    localStorage.setItem("ins_period", JSON.stringify({ key: "custom", from: "2026-08-01", to: "2026-08-15" }));
    renderProbe();
    await flushProvider();

    expect(screen.getByTestId("period")).toHaveTextContent("custom");
    expect(screen.getByTestId("range")).toHaveTextContent("&from=2026-08-01&to=2026-08-15");
  });
});
