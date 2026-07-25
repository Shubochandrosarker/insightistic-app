import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { routerMock } from "./setup";

/**
 * The MFA login step. The invariant under test is that a correct password
 * alone never lands the user in the dashboard when two-factor is on — the
 * page must stop and ask for a code.
 */

const authMocks = vi.hoisted(() => ({
  login: vi.fn(),
  completeMfa: vi.fn(),
}));

vi.mock("@/lib/auth", () => ({
  useAuth: () => ({ ...authMocks, user: null, loading: false }),
}));

vi.mock("@/components/auth/OAuthButtons", () => ({
  OAuthButtons: () => null,
}));

import Login from "@/app/login/page";

async function signIn() {
  const user = userEvent.setup();
  render(<Login />);
  await user.type(screen.getByLabelText("Email"), "owner@example.com");
  await user.type(screen.getByLabelText("Password"), "secret-password");
  await user.click(screen.getByRole("button", { name: /Sign in/ }));
  return user;
}

describe("Login with two-factor", () => {
  beforeEach(() => vi.clearAllMocks());

  it("goes straight to the dashboard when MFA is off", async () => {
    authMocks.login.mockResolvedValue({ mfaRequired: false, user: { is_super_admin: false } });

    await signIn();

    await waitFor(() => expect(routerMock.push).toHaveBeenCalledWith("/dashboard"));
  });

  it("stops for a code instead of navigating when MFA is on", async () => {
    authMocks.login.mockResolvedValue({ mfaRequired: true, challenge: "chal-123" });

    await signIn();

    expect(await screen.findByText("Two-factor authentication")).toBeInTheDocument();
    // The critical assertion: the password alone did not get them in.
    expect(routerMock.push).not.toHaveBeenCalled();
  });

  it("exchanges the code for a session and then navigates", async () => {
    authMocks.login.mockResolvedValue({ mfaRequired: true, challenge: "chal-123" });
    authMocks.completeMfa.mockResolvedValue({ is_super_admin: true });

    const user = await signIn();
    await screen.findByText("Two-factor authentication");

    await user.type(screen.getByLabelText("Authentication code"), "123456");
    await user.click(screen.getByRole("button", { name: /Verify/ }));

    await waitFor(() => expect(authMocks.completeMfa).toHaveBeenCalledWith("chal-123", "123456"));
    // Super admins land on /admin.
    await waitFor(() => expect(routerMock.push).toHaveBeenCalledWith("/admin"));
  });

  it("sends the user back to the start when the challenge has expired", async () => {
    authMocks.login.mockResolvedValue({ mfaRequired: true, challenge: "chal-123" });
    authMocks.completeMfa.mockRejectedValue({
      status: 422,
      message: "That sign-in attempt expired.",
      data: { code: "challenge_expired" },
    });

    const user = await signIn();
    await screen.findByText("Two-factor authentication");

    await user.type(screen.getByLabelText("Authentication code"), "123456");
    await user.click(screen.getByRole("button", { name: /Verify/ }));

    // A burned challenge cannot be retried — back to the password form.
    expect(await screen.findByLabelText("Password")).toBeInTheDocument();
    expect(routerMock.push).not.toHaveBeenCalled();
  });

  it("keeps the code step open for a merely wrong code", async () => {
    authMocks.login.mockResolvedValue({ mfaRequired: true, challenge: "chal-123" });
    authMocks.completeMfa.mockRejectedValue({
      status: 422,
      message: "That code is not valid.",
      data: { code: "invalid_code" },
    });

    const user = await signIn();
    await screen.findByText("Two-factor authentication");

    await user.type(screen.getByLabelText("Authentication code"), "000000");
    await user.click(screen.getByRole("button", { name: /Verify/ }));

    expect(await screen.findByText("That code is not valid.")).toBeInTheDocument();
    expect(screen.getByLabelText("Authentication code")).toBeInTheDocument();
  });

  it("mentions recovery codes so a lost device is not a dead end", async () => {
    authMocks.login.mockResolvedValue({ mfaRequired: true, challenge: "chal-123" });

    await signIn();

    expect(await screen.findByText(/recovery codes/i)).toBeInTheDocument();
  });
});
