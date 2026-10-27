import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";

const push = vi.fn();
const apiPost = vi.fn();
vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(),
  useRouter: () => ({ push, replace: vi.fn() }),
  usePathname: () => "/dashboard/copilot",
}));
vi.mock("@/lib/dashboard", () => ({
  PERIODS: [{ value: "last_30_days", label: "Last 30 days" }],
  useDashboard: () => ({ siteId: 1, sites: [{ id: 1, name: "Site" }], period: "last_30_days", setSiteId: vi.fn(), setPeriod: vi.fn() }),
}));
vi.mock("@/lib/useApi", () => ({ useApi: () => ({ data: null, loading: false, error: null, reload: vi.fn() }) }));
vi.mock("@/lib/api", () => ({
  apiGet: vi.fn(async () => ({})), apiPatch: vi.fn(), apiDelete: vi.fn(), getToken: () => "t",
  apiPost: (...a: any[]) => apiPost(...a),
}));

import AgentsPage from "@/app/dashboard/agents/page";
import CopilotPage from "@/app/dashboard/copilot/page";

describe("agent workspace hand-off", () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it("opens the agent workspace with its prompt, site and period", () => {
    render(<AgentsPage />);
    fireEvent.click(screen.getAllByText(/Open workspace/)[3]);
    const url = push.mock.calls[0][0] as string;
    const q = new URLSearchParams(url.split("?")[1]);
    expect(url.startsWith("/dashboard/copilot?")).toBe(true);
    expect(q.get("agent")).toBe("article");
    expect(q.get("prompt")).toMatch(/articles/i);
    expect(q.get("site_id")).toBe("1");
    expect(q.get("period")).toBe("last_30_days");
  });

  it("auto-submits the handed-off prompt with the agent", async () => {
    apiPost.mockResolvedValue({ thread: { id: 7 } });
    const fetchMock = vi.fn(async () => new Response("", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);
    window.history.pushState(null, "", "/dashboard/copilot?agent=growth&prompt=Grow%20it&site_id=1&period=last_30_days");
    render(<CopilotPage />);
    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    const [u, init] = fetchMock.mock.calls[0] as any[];
    expect(u).toBe("/api/copilot/threads/7/messages");
    expect(JSON.parse(init.body)).toMatchObject({ content: "Grow it", agent: "growth", site_id: 1, period: "last_30_days" });
    expect(window.location.search).toBe("");
    vi.unstubAllGlobals();
  });
});
