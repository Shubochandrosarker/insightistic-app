import { describe, it, expect, vi, beforeEach, type Mock } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { routerMock } from "./setup";

/**
 * AppSumo claim page: pre-check → form → POST → token adoption. The API layer
 * is stubbed per the house pattern (vitest.config.mts) — the page runs real.
 */
let applyTokenMock: Mock;
let apiGetMock: Mock;
let apiPostMock: Mock;

vi.mock("@/lib/auth", () => ({
  useAuth: () => ({ applyToken: (...args: unknown[]) => applyTokenMock(...args) }),
}));

vi.mock("@/lib/api", () => ({
  apiGet: (...args: unknown[]) => apiGetMock(...args),
  apiPost: (...args: unknown[]) => apiPostMock(...args),
}));

import AppSumoClaim from "@/app/appsumo/claim/page";

function mount(query: string) {
  // The page reads window.location.search (register-page pattern, avoids the
  // Suspense boundary), so drive it through history.
  window.history.pushState({}, "", `/appsumo/claim${query}`);
  return render(<AppSumoClaim />);
}

describe("AppSumo claim page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    applyTokenMock = vi.fn();
    apiGetMock = vi.fn();
    apiPostMock = vi.fn();
  });

  it("pre-checks the license, shows the plan, and claims through to /dashboard", async () => {
    apiGetMock.mockResolvedValue({ status: "active", tier: 2, plan_slug: "appsumo_studio", linked: false });
    apiPostMock.mockResolvedValue({ token: "tok", plan_slug: "appsumo_studio", sites_limit: 10 });

    mount("?license=lic-key");
    expect(await screen.findByText(/AppSumo legacy tier 2 — lifetime/i)).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText("Email"), "buyer@example.com");
    await userEvent.type(screen.getByLabelText("Password"), "super-secret-9");
    await userEvent.type(screen.getByLabelText("Full name"), "Sumo Buyer");
    await userEvent.click(screen.getByRole("button", { name: /Activate my license/i }));

    await waitFor(() =>
      expect(apiPostMock).toHaveBeenCalledWith("/appsumo/claim", {
        license_key: "lic-key",
        email: "buyer@example.com",
        password: "super-secret-9",
        name: "Sumo Buyer",
      }),
    );
    await waitFor(() => expect(applyTokenMock).toHaveBeenCalledWith("tok"));
    await waitFor(() => expect(routerMock.push).toHaveBeenCalledWith("/dashboard"));
  });

  it("maps an unknown license to the friendly 404 message and renders no form", async () => {
    apiGetMock.mockRejectedValue({ status: 404, message: "Request failed (HTTP 404)" });

    mount("?license=ghost");
    expect(
      await screen.findByText(/could not find that AppSumo license/i),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button")).toBeNull();
    expect(apiPostMock).not.toHaveBeenCalled();
  });

  it("explains itself when the link carries no license reference", async () => {
    mount("");
    expect(await screen.findByText(/missing its license reference/i)).toBeInTheDocument();
    expect(apiGetMock).not.toHaveBeenCalled();
  });

  it("blocks submission client-side on a too-short password", async () => {
    apiGetMock.mockResolvedValue({ status: "active", tier: 1, plan_slug: "appsumo_solo", linked: false });

    mount("?license=lic-key");
    await screen.findByText(/AppSumo legacy tier 1 — lifetime/i);

    await userEvent.type(screen.getByLabelText("Email"), "buyer@example.com");
    await userEvent.type(screen.getByLabelText("Password"), "short");
    await userEvent.click(screen.getByRole("button", { name: /Activate my license/i }));

    expect(await screen.findByText(/at least 8 characters/i)).toBeInTheDocument();
    expect(apiPostMock).not.toHaveBeenCalled();
    expect(applyTokenMock).not.toHaveBeenCalled();
  });
});
