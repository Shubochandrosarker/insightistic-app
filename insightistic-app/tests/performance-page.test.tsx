import { screen, render } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { expect, it, vi } from "vitest";
import PerformancePage from "@/app/dashboard/performance/page";
import { apiGet } from "@/lib/api";

vi.mock("@/lib/api", () => ({ apiGet: vi.fn(), apiPost: vi.fn() }));
vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ siteId: 1, sites: [{ id: 1, name: "Test Site" }] }),
}));

it("shows INP only for the device result that has a measurement", async () => {
  vi.mocked(apiGet).mockImplementation(async (path) => {
    if (path.includes("/history")) return { runs: [] };
    const desktop = path.includes("strategy=desktop");
    return {
      strategy: desktop ? "desktop" : "mobile",
      run: {
        lcp_ms: desktop ? 800 : 1650,
        inp_ms: desktop ? 120 : null,
        inp_status: desktop ? "good" : null,
        cls: 0.259,
      },
    };
  });

  const user = userEvent.setup();
  render(<PerformancePage />);

  expect(await screen.findByText("1.65s")).toBeInTheDocument();
  expect(screen.getByText("0.259")).toBeInTheDocument();
  expect(screen.queryByText("INP")).not.toBeInTheDocument();

  await user.click(screen.getByRole("button", { name: "desktop" }));
  expect(await screen.findByText("120ms")).toBeInTheDocument();
  expect(screen.getByText("INP")).toBeInTheDocument();

  await user.click(screen.getByRole("button", { name: "mobile" }));
  expect(await screen.findByText("1.65s")).toBeInTheDocument();
  expect(screen.queryByText("INP")).not.toBeInTheDocument();
});
