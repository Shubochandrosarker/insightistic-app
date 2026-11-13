import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { routerMock } from "./setup";
import { LayoutGrid, Bot, ShoppingCart } from "lucide-react";

/**
 * The UX safety net (spec §9): route-level error boundaries, the command
 * palette, and the notification centre.
 *
 * The boundary tests matter most — a boundary that renders nothing useful,
 * or offers no way out, is just a prettier crash.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPatch: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
  getToken: vi.fn(() => "t"),
}));

vi.mock("@/lib/api", () => apiMocks);

import { RouteError } from "@/components/dashboard/RouteError";
import { CommandPalette } from "@/components/app-shell/CommandPalette";
import { NotificationCenter } from "@/components/app-shell/NotificationCenter";

describe("Route error boundary", () => {
  beforeEach(() => vi.clearAllMocks());

  it("shows the error's own message rather than a generic apology", () => {
    render(<RouteError error={new Error("Site not found")} reset={() => {}} />);

    expect(screen.getByText("Site not found")).toBeInTheDocument();
  });

  it("always offers a way out", async () => {
    const reset = vi.fn();
    const user = userEvent.setup();

    render(<RouteError error={new Error("boom")} reset={reset} />);

    await user.click(screen.getByRole("button", { name: "Try again" }));
    expect(reset).toHaveBeenCalled();

    // ...and an escape hatch when retrying won't help.
    expect(screen.getByRole("link", { name: "Back to safety" })).toHaveAttribute("href", "/dashboard");
  });

  it("surfaces the digest so a report can be traced", () => {
    const error = Object.assign(new Error("boom"), { digest: "abc123" });
    render(<RouteError error={error} reset={() => {}} />);

    expect(screen.getByText(/abc123/)).toBeInTheDocument();
  });

  it("still renders when the error carries no message", () => {
    render(<RouteError error={new Error("")} reset={() => {}} />);

    expect(screen.getByText(/stopped this page from rendering/)).toBeInTheDocument();
  });
});

const NAV = [
  { href: "/dashboard", label: "Overview", icon: LayoutGrid },
  { href: "/dashboard/copilot", label: "Agent JOLLY", icon: Bot },
  { href: "/dashboard/orders", label: "Orders", icon: ShoppingCart },
];

describe("Command palette", () => {
  beforeEach(() => vi.clearAllMocks());

  it("stays hidden until the keyboard shortcut", async () => {
    const user = userEvent.setup();
    render(<CommandPalette items={NAV} />);

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();

    await user.keyboard("{Meta>}k{/Meta}");
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
  });

  it("matches on subsequence, not just substring", async () => {
    const user = userEvent.setup();
    render(<CommandPalette items={NAV} />);
    await user.keyboard("{Control>}k{/Control}");

    // "ajl" is not a substring of "Agent JOLLY", but it is a subsequence.
    await user.type(await screen.findByLabelText("Search pages"), "ajl");

    expect(screen.getByText("Agent JOLLY")).toBeInTheDocument();
    expect(screen.queryByText("Orders")).not.toBeInTheDocument();
  });

  it("navigates with the keyboard and closes", async () => {
    const user = userEvent.setup();
    render(<CommandPalette items={NAV} />);
    await user.keyboard("{Control>}k{/Control}");

    await user.type(await screen.findByLabelText("Search pages"), "ordr");
    await user.keyboard("{Enter}");

    await waitFor(() => expect(routerMock.push).toHaveBeenCalledWith("/dashboard/orders"));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("says so when nothing matches", async () => {
    const user = userEvent.setup();
    render(<CommandPalette items={NAV} />);
    await user.keyboard("{Control>}k{/Control}");

    await user.type(await screen.findByLabelText("Search pages"), "zzzzz");

    expect(screen.getByText(/Nothing matches/)).toBeInTheDocument();
  });

  it("closes on Escape", async () => {
    const user = userEvent.setup();
    render(<CommandPalette items={NAV} />);
    await user.keyboard("{Control>}k{/Control}");
    await screen.findByRole("dialog");

    await user.keyboard("{Escape}");

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
  });
});

describe("Notification centre", () => {
  beforeEach(() => vi.clearAllMocks());

  const notifications = [
    { id: 1, type: "trial", title: "Trial ends soon", message: "2 days left", action_url: "/dashboard/billing", status: "unread", created_at: new Date().toISOString(), read_at: null },
    { id: 2, type: "plugin", title: "Plugin connected", message: null, action_url: null, status: "read", created_at: new Date().toISOString(), read_at: new Date().toISOString() },
    { id: 3, type: "old", title: "Dismissed already", message: null, action_url: null, status: "dismissed", created_at: new Date().toISOString(), read_at: null },
  ];

  it("badges only unread, and hides dismissed entries", async () => {
    apiMocks.apiGet.mockResolvedValue({ notifications });
    const user = userEvent.setup();

    render(<NotificationCenter />);

    // One unread of the two non-dismissed.
    const bell = await screen.findByRole("button", { name: "Notifications, 1 unread" });
    await user.click(bell);

    expect(screen.getByText("Trial ends soon")).toBeInTheDocument();
    expect(screen.getByText("Plugin connected")).toBeInTheDocument();
    expect(screen.queryByText("Dismissed already")).not.toBeInTheDocument();
  });

  it("does not mark everything read just because the panel opened", async () => {
    apiMocks.apiGet.mockResolvedValue({ notifications });
    const user = userEvent.setup();

    render(<NotificationCenter />);
    await user.click(await screen.findByRole("button", { name: /Notifications/ }));

    // Opening is not reading — silently clearing unseen notices is the bug
    // this guards against.
    expect(apiMocks.apiPatch).not.toHaveBeenCalled();
  });

  it("marks one read on request", async () => {
    apiMocks.apiGet.mockResolvedValue({ notifications });
    apiMocks.apiPatch.mockResolvedValue({ ok: true });
    const user = userEvent.setup();

    render(<NotificationCenter />);
    await user.click(await screen.findByRole("button", { name: /Notifications/ }));
    await user.click(screen.getByRole("button", { name: "Mark read" }));

    expect(apiMocks.apiPatch).toHaveBeenCalledWith("/notifications/1", { status: "read" });
  });

  it("shows an empty state when there is nothing to read", async () => {
    apiMocks.apiGet.mockResolvedValue({ notifications: [] });
    const user = userEvent.setup();

    render(<NotificationCenter />);
    await user.click(await screen.findByRole("button", { name: "Notifications" }));

    expect(screen.getByText("You’re all caught up.")).toBeInTheDocument();
  });
});
