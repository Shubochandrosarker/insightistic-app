import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const mocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
  reload: vi.fn(),
}));

vi.mock("@/lib/api", () => ({
  apiGet: (...args: unknown[]) => mocks.apiGet(...args),
  apiPost: (...args: unknown[]) => mocks.apiPost(...args),
  apiDelete: (...args: unknown[]) => mocks.apiDelete(...args),
}));

vi.mock("@/lib/useApi", () => ({
  useApi: () => ({
    data: { sources: [cloudflareSource], google_account: null },
    loading: false,
    error: null,
    reload: mocks.reload,
  }),
}));

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ siteId: 1, sites: [{ id: 1, name: "WordPressistic" }] }),
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

const cloudflareSource = {
  source: "cloudflare",
  state: "connected",
  connection_method: "api_token",
  label: "chatbotistic.com",
  last_successful_at: null,
  latest_data_at: null,
  freshness: "unknown",
  last_error_code: "provider_auth_failed",
  last_error_message: "Zone does not have access to HTTP request analytics.",
  connectable: true,
  managed_by_platform: false,
  auth_type: "api_token",
  configured: true,
  connected: true,
  detail: { connected_account: null, resource: { id: "zone-old" } },
  plugin_fed: null,
  attachable_from_org: false,
};

import IntegrationsPage from "@/app/dashboard/integrations/page";

describe("integrations page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("lets a connected Cloudflare integration change its selected zone", async () => {
    mocks.apiGet.mockResolvedValue({
      resources: [
        { id: "zone-wordpressistic", name: "wordpressistic.com", account: "WordPressistic" },
      ],
    });
    mocks.apiPost.mockResolvedValue({});

    render(<IntegrationsPage />);
    expect(screen.queryByText("Choose which zone this site should report on.")).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Change zone" }));

    expect(await screen.findByRole("button", { name: /wordpressistic\.com/ })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /wordpressistic\.com/ }));

    await waitFor(() =>
      expect(mocks.apiPost).toHaveBeenCalledWith("/sites/1/integrations/cloudflare/select", {
        id: "zone-wordpressistic",
        name: "wordpressistic.com",
      }),
    );
    expect(mocks.reload).toHaveBeenCalled();
  });
});
