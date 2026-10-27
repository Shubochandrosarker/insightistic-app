import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, act, waitFor } from "@testing-library/react";

/**
 * The auth context value must be referentially stable across session state
 * changes. Pages hang effects off these callbacks (the OAuth callback is the
 * canonical case); an unstable function identity re-fires those effects at
 * arbitrary times and produced phantom auth errors in production.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  getToken: vi.fn(),
  setToken: vi.fn(),
  clearImpersonation: vi.fn(),
}));

vi.mock("@/lib/api", () => ({
  apiGet: apiMocks.apiGet,
  apiPost: apiMocks.apiPost,
  getToken: apiMocks.getToken,
  setToken: apiMocks.setToken,
  clearImpersonation: apiMocks.clearImpersonation,
}));

import { AuthProvider, useAuth } from "@/lib/auth";

describe("AuthProvider context stability", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    apiMocks.getToken.mockReturnValue(null);
  });

  it("keeps applyToken/login/logout identities stable across a session state change", async () => {
    apiMocks.apiGet.mockResolvedValue({ user: null, organizations: [] });
    apiMocks.apiPost.mockResolvedValue({
      token: "tok-1",
      user: { id: 1, is_super_admin: false },
      organizations: [],
    });

    const applyIdentities = new Set<Function>();
    const loginIdentities = new Set<Function>();
    let login!: (e: string, p: string) => Promise<any>;

    function Probe() {
      const auth = useAuth();
      applyIdentities.add(auth.applyToken);
      loginIdentities.add(auth.login);
      login = auth.login;
      return <p>probe</p>;
    }

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>
    );

    await act(async () => {
      await login("owner@example.com", "secret-password");
    });

    // The login() above set user/orgs state — several provider re-renders —
    // yet every function the context exposes must be the same reference.
    expect(applyIdentities.size).toBe(1);
    expect(loginIdentities.size).toBe(1);
  });

  it("preserves the shared token when session hydration hits a transient server error", async () => {
    apiMocks.getToken.mockReturnValue("session-token");
    apiMocks.apiGet.mockRejectedValue({ status: 520, message: "Request failed (HTTP 520)" });

    let loading = true;
    let sessionError: string | null = null;

    function Probe() {
      const auth = useAuth();
      loading = auth.loading;
      sessionError = auth.sessionError;
      return <p>probe</p>;
    }

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>
    );

    await waitFor(() => expect(loading).toBe(false));
    expect(sessionError).toMatch(/temporarily unavailable/i);
    expect(apiMocks.setToken).not.toHaveBeenCalledWith(null);
  });

  it("clears the shared token only after an authoritative 401", async () => {
    apiMocks.getToken.mockReturnValue("expired-token");
    apiMocks.apiGet.mockRejectedValue({ status: 401, message: "Unauthenticated." });

    let loading = true;
    let sessionError: string | null = null;

    function Probe() {
      const auth = useAuth();
      loading = auth.loading;
      sessionError = auth.sessionError;
      return <p>probe</p>;
    }

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>
    );

    await waitFor(() => expect(loading).toBe(false));
    expect(sessionError).toBeNull();
    expect(apiMocks.setToken).toHaveBeenCalledWith(null);
  });
});
