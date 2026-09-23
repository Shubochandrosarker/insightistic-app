/**
 * Add Website 2.0 (2026-09-23 milestone): the conversion-critical first-run
 * page — Google import option + plugin-free URL option, honest empty states.
 */
import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ sites: [], siteId: null, reloadSites: vi.fn(async () => {}), setSiteId: vi.fn() }),
}));

const apiGet = vi.hoisted(() => vi.fn());
const apiPost = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", () => ({
  apiGet: (...a: unknown[]) => apiGet(...a),
  apiPost: (...a: unknown[]) => apiPost(...a),
}));

import AddWebsitePage from "@/app/dashboard/add-website/page";

describe("Add website page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // @ts-expect-error test stub
    window.location = { href: "http://localhost/dashboard/add-website" };
  });

  it("offers both options: GSC import (recommended) and plain URL", async () => {
    render(<AddWebsitePage />);
    expect(await screen.findByText(/Import from Google Search Console/i)).toBeTruthy();
    expect(screen.getByText(/Enter a website URL/i)).toBeTruthy();
    expect(screen.getByText(/No plugin required/i)).toBeTruthy();
  });

  it("routes through org google connect when not connected yet", async () => {
    apiGet.mockImplementation((p: string) => {
      if (p === "/integrations/google/account") {
        return Promise.resolve({ connected: false, configured: true, account: null });
      }
      return Promise.resolve({});
    });
    apiPost.mockResolvedValue({ authorize_url: "https://accounts.google.test/consent" });

    render(<AddWebsitePage />);
    fireEvent.click(await screen.findByText("Start with Google"));
    await waitFor(() => fireEvent.click(screen.getByText("Connect Google")));

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith("/integrations/google/account/authorize-url"),
    );
    expect(window.location.href).toContain("accounts.google.test");
  });

  it("lists GSC properties for multi-select when google is connected and imports them", async () => {
    apiGet.mockImplementation((p: string) => {
      if (p === "/integrations/google/account") {
        return Promise.resolve({
          connected: true,
          configured: true,
          account: { account_email: "owner@gmail.com", account_id: "sub-1" },
        });
      }
      if (p === "/integrations/google/gsc/properties") {
        return Promise.resolve({
          properties: [
            { property: "sc-domain:example.com", property_type: "domain", permission_level: "siteOwner", domain: "example.com", matched_site: null },
            { property: "https://shop.example.com/", property_type: "url_prefix", permission_level: "siteFullUser", domain: "shop.example.com", matched_site: { id: 9, name: "Shop" } },
          ],
        });
      }
      return Promise.resolve({});
    });
    apiPost.mockImplementation((p: string) => {
      if (p === "/integrations/google/gsc/import") {
        return Promise.resolve({
          created: [{ site_id: 1, name: "example.com", domain: "example.com", property: "sc-domain:example.com", is_new: true }],
          attached: [],
          skipped: [],
        });
      }
      return Promise.resolve({});
    });

    render(<AddWebsitePage />);
    fireEvent.click(await screen.findByText("Start with Google"));

    expect(await screen.findByText("example.com")).toBeTruthy();
    expect(screen.getByText(/already in workspace/)).toBeTruthy();

    fireEvent.click(screen.getAllByRole("checkbox")[0]);
    fireEvent.click(screen.getByText(/Import 1 website/));

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith("/integrations/google/gsc/import", {
        properties: ["sc-domain:example.com"],
      }),
    );
    expect(await screen.findByText(/Import complete/i)).toBeTruthy();
    expect(screen.getByText(/initial search sync, crawl, uptime monitoring and PageSpeed are running/i)).toBeTruthy();
  });

  it("creates a site from a bare URL without any plugin steps", async () => {
    apiPost.mockImplementation((p: string) => {
      if (p === "/sites") {
        return Promise.resolve({ site: { id: 5, platform: "generic" }, kickoff: ["crawler", "uptime"] });
      }
      return Promise.resolve({});
    });

    render(<AddWebsitePage />);
    const input = await screen.findByPlaceholderText("https://example.com");
    fireEvent.change(input, { target: { value: "example.com" } });
    fireEvent.click(screen.getByText("Add website"));

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith("/sites", { domain: "example.com" }),
    );
    expect(window.location.href).toBe("/dashboard");
  });
});
