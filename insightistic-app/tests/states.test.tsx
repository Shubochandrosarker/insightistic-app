import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ErrorState, DegradedBanner, StaleBadge, SourceFreshness } from "@/components/dashboard/states";

/**
 * The Phase-3 state components exist so a failing API can no longer dress up
 * as an empty site. These tests pin the contract each state promises.
 */
describe("ErrorState", () => {
  it("renders the message under the default title and fires retry", async () => {
    const onRetry = vi.fn();
    const user = userEvent.setup();
    render(<ErrorState message="Server exploded" onRetry={onRetry} />);

    expect(screen.getByText("Something went wrong")).toBeInTheDocument();
    expect(screen.getByText("Server exploded")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Try again" }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it("supports a custom title and hides retry when no callback is given", () => {
    render(<ErrorState title="Search Console" message="Sync failed" />);
    expect(screen.getByText("Search Console")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Try again" })).not.toBeInTheDocument();
  });
});

describe("DegradedBanner", () => {
  it("renders the degradation message", () => {
    render(<DegradedBanner message="Data shown may be partial while the sync catches up." />);
    expect(screen.getByText("Data shown may be partial while the sync catches up.")).toBeInTheDocument();
  });
});

describe("StaleBadge", () => {
  it("renders the default and custom labels", () => {
    const { rerender } = render(<StaleBadge />);
    expect(screen.getByText("Stale data")).toBeInTheDocument();

    rerender(<StaleBadge label="Older than 24h" />);
    expect(screen.getByText("Older than 24h")).toBeInTheDocument();
  });
});

describe("SourceFreshness", () => {
  it("says never when there is no timestamp", () => {
    render(<SourceFreshness label="Last sync" asOf={null} />);
    expect(screen.getByText(/never/).textContent).toBe("Last sync · never");
  });

  it("shows a relative time when a timestamp is present", () => {
    const fiveMinutesAgo = new Date(Date.now() - 5 * 60 * 1000).toISOString();
    render(<SourceFreshness label="Last sync" asOf={fiveMinutesAgo} />);
    const chip = screen.getByText(/ago/);
    expect(chip.textContent).toMatch(/^Last sync · \d+[smhd] ago$/);
  });
});
