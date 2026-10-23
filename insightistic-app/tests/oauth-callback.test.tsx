import { describe, it, expect, vi, beforeEach, type Mock } from "vitest";
import { render, screen, waitFor, act } from "@testing-library/react";
import { routerMock } from "./setup";

/**
 * OAuth callback regression suite. The token arrives in the URL hash, which
 * the page strips immediately. The historical bug: the effect re-ran with a
 * fresh applyToken identity (unstable context value) while the first
 * applyToken call was still in flight, re-parsed the now-empty hash, and
 * flashed "No sign-in token was returned" right before the dashboard loaded.
 */

let applyTokenMock: Mock;

vi.mock("@/lib/auth", () => ({
  useAuth: () => ({ applyToken: applyTokenMock }),
}));

import OAuthCallback from "@/app/auth/callback/page";

function mountWithHash(hash: string) {
  window.location.hash = hash;
  return render(<OAuthCallback />);
}

describe("OAuth callback", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.location.hash = "";
    applyTokenMock = vi.fn();
  });

  it("does not surface a no-token error when the effect re-runs mid-flight", async () => {
    const firstCall = applyTokenMock;
    let resolveSession!: (u: any) => void;
    firstCall.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveSession = resolve;
        })
    );

    const { rerender } = mountWithHash("#token=abc123");

    // Simulate the historical failure mode: the auth provider re-renders with
    // a fresh applyToken identity while the first call is still pending.
    applyTokenMock = vi.fn();
    rerender(<OAuthCallback />);
    applyTokenMock = vi.fn();
    rerender(<OAuthCallback />);

    await act(async () => {
      resolveSession({ is_super_admin: false });
    });

    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith("/dashboard"));
    expect(screen.queryByText(/No sign-in token/i)).not.toBeInTheDocument();
    // The token is consumed exactly once — the re-runs never re-invoked it.
    expect(firstCall).toHaveBeenCalledTimes(1);
    expect(applyTokenMock).not.toHaveBeenCalled();
  });

  it("sends super admins to /admin and everyone else to /dashboard", async () => {
    applyTokenMock.mockResolvedValue({ is_super_admin: true });
    mountWithHash("#token=adm");
    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith("/admin"));
  });

  it("maps provider error codes to a friendly message and never calls the API", async () => {
    mountWithHash("#error=oauth_failed");
    expect(await screen.findByText(/couldn't complete sign-in/i)).toBeInTheDocument();
    expect(applyTokenMock).not.toHaveBeenCalled();
    expect(routerMock.replace).not.toHaveBeenCalled();
  });

  it("shows the no-token error only when the hash genuinely has no token", async () => {
    mountWithHash("");
    expect(await screen.findByText(/No sign-in token was returned/i)).toBeInTheDocument();
    expect(applyTokenMock).not.toHaveBeenCalled();
  });

  it("shows a finish-failure message when applyToken rejects", async () => {
    applyTokenMock.mockRejectedValue(new Error("boom"));
    mountWithHash("#token=xyz");
    expect(await screen.findByText(/couldn't finish signing you in/i)).toBeInTheDocument();
    expect(routerMock.replace).not.toHaveBeenCalled();
  });
});
