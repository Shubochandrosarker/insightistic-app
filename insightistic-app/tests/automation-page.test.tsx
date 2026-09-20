import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 14 workstream UI (Automation Studio, roadmap doc 19). Mocking mirrors
 * tests/traffic-page.test.tsx — the API layer is stubbed, the real page
 * renders. Pins: the rules table reading labels from the catalog, coming_soon
 * catalog entries rendering disabled in the pickers, rule creation POSTing the
 * catalog-derived config shapes the controller expects (trigger_config /
 * action_params), the active toggle PATCHing is_active, and the per-rule runs
 * log rendering fired_at / trigger context / result.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPatch: vi.fn(),
  apiDelete: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({
    siteId: 1,
    sites: [{ id: 1, name: "Test Shop", domain: "testshop.com" }],
  }),
}));

import AutomationStudioPage from "@/app/dashboard/automation/page";

const hoursAgo = (n: number) => new Date(Date.now() - n * 3_600_000).toISOString();

const catalogPayload = {
  triggers: [
    {
      type: "schedule",
      label: "On a schedule",
      description: "Fires on its own cadence.",
      status: "active",
      rate_limit: "Rate limited by cadence.",
      config_fields: [{ key: "frequency", label: "Frequency", type: "select", options: ["hourly", "daily", "weekly"] }],
    },
    {
      type: "ai_visibility_drop",
      label: "AI visibility drop",
      description: "Not implemented yet.",
      status: "coming_soon",
      config_fields: [],
    },
  ],
  actions: [
    {
      type: "send_notification_email",
      label: "Send notification email",
      description: "Emails the organization owner.",
      status: "active",
      config_fields: [
        { key: "subject", label: "Email subject", type: "text" },
        { key: "body", label: "Email body", type: "text" },
      ],
    },
    {
      type: "in_app_notification",
      label: "In-app notification",
      description: "Not implemented yet.",
      status: "coming_soon",
      config_fields: [],
    },
  ],
};

const rulesPayload = {
  automation_rules: [
    {
      id: 7,
      name: "Nightly rank watch",
      is_active: true,
      trigger_type: "schedule",
      trigger_config_json: { frequency: "daily" },
      action_type: "send_notification_email",
      action_params_json: { subject: "Nightly digest", body: "Here is your digest." },
      last_fired_at: hoursAgo(5),
      last_run_status: "success",
      created_at: hoursAgo(72),
    },
    {
      id: 8,
      name: "Alert relay",
      is_active: false,
      trigger_type: "alert_created",
      trigger_config_json: {},
      action_type: "send_webhook",
      action_params_json: { url: "https://example.com/hook" },
      last_fired_at: null,
      last_run_status: null,
      created_at: hoursAgo(24),
    },
  ],
};

const runsPayload = {
  automation_runs: [
    {
      id: 91,
      fired_at: hoursAgo(2),
      status: "failed",
      trigger_context_json: { manual: true },
      action_result_json: { success: false, error: "no_recipient" },
      error_message: "no_recipient",
    },
    {
      id: 90,
      fired_at: hoursAgo(26),
      status: "success",
      trigger_context_json: { frequency: "daily" },
      action_result_json: { success: true },
      error_message: null,
    },
  ],
};

function mockApi() {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/automation-rules/catalog")) return Promise.resolve(catalogPayload);
    if (p.includes("/automation-rules") && p.endsWith("/runs")) return Promise.resolve(runsPayload);
    if (p.includes("/automation-rules")) return Promise.resolve(rulesPayload);
    return Promise.resolve({});
  });
  apiMocks.apiPost.mockResolvedValue({ automation_rule: { id: 9 } });
  apiMocks.apiPatch.mockResolvedValue({ automation_rule: { id: 7 } });
  apiMocks.apiDelete.mockResolvedValue({ deleted: true });
}

describe("Automation Studio page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal("confirm", vi.fn(() => true));
    mockApi();
  });

  it("renders the rules table with labels resolved from the catalog", async () => {
    render(<AutomationStudioPage />);

    expect((await screen.findAllByText("Nightly rank watch")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Alert relay").length).toBeGreaterThan(0);

    // Catalog labels, not raw type slugs.
    expect(screen.getAllByText("On a schedule").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Send notification email").length).toBeGreaterThan(0);
    // Unknown-to-catalog types fall back to the raw type instead of vanishing.
    expect(screen.getAllByText("alert_created").length).toBeGreaterThan(0);

    // Last fired + last status columns.
    expect(screen.getAllByText(/5h ago/).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Success").length).toBeGreaterThan(0);
    // The paused rule shows a neutral dash, never a fake status.
    expect(screen.getAllByText("—").length).toBeGreaterThan(0);
  });

  it("renders the trigger picker from the catalog with coming_soon entries disabled", async () => {
    const user = userEvent.setup();
    render(<AutomationStudioPage />);

    await screen.findAllByText("Nightly rank watch");
    await user.click(screen.getByRole("button", { name: /New rule/ }));

    const triggerSelect = screen.getByLabelText("Trigger type");
    const actionSelect = screen.getByLabelText("Action type");

    expect(within(triggerSelect).getByRole("option", { name: "AI visibility drop — coming soon" })).toBeDisabled();
    expect(within(triggerSelect).getByRole("option", { name: "On a schedule" })).not.toBeDisabled();
    expect(within(actionSelect).getByRole("option", { name: "In-app notification — coming soon" })).toBeDisabled();
    expect(within(actionSelect).getByRole("option", { name: "Send notification email" })).not.toBeDisabled();
  });

  it("creates a rule POSTing the catalog-derived config shapes", async () => {
    const user = userEvent.setup();
    render(<AutomationStudioPage />);

    await screen.findAllByText("Nightly rank watch");
    await user.click(screen.getByRole("button", { name: /New rule/ }));

    await user.type(screen.getByLabelText("Rule name"), "Morning digest");
    await user.selectOptions(screen.getByLabelText("Trigger type"), "schedule");
    // The Frequency field exists ONLY because the catalog's schedule entry
    // declares it — its default is the first option, then the user picks daily.
    expect(screen.getByLabelText("Frequency")).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText("Frequency"), "daily");
    await user.selectOptions(screen.getByLabelText("Action type"), "send_notification_email");
    await user.type(screen.getByLabelText("Email subject"), "Rank report");
    await user.click(screen.getByRole("button", { name: "Create rule" }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/automation-rules", {
      name: "Morning digest",
      trigger_type: "schedule",
      trigger_config: { frequency: "daily" },
      action_type: "send_notification_email",
      action_params: { subject: "Rank report", body: "" },
    });
    expect(await screen.findByText('Rule "Morning digest" created — it runs itself from now on.')).toBeInTheDocument();
  });

  it("toggles a rule by PATCHing is_active", async () => {
    const user = userEvent.setup();
    render(<AutomationStudioPage />);

    const box = (await screen.findAllByLabelText("Toggle Nightly rank watch"))[0];
    expect(box).toBeChecked();

    await user.click(box);

    expect(apiMocks.apiPatch).toHaveBeenCalledWith("/sites/1/automation-rules/7", { is_active: false });
  });

  it("opens a per-rule runs log with fired time, trigger context and result", async () => {
    const user = userEvent.setup();
    render(<AutomationStudioPage />);

    await screen.findAllByText("Nightly rank watch");
    await user.click(screen.getAllByRole("button", { name: "Runs" })[0]);

    expect(apiMocks.apiGet).toHaveBeenCalledWith("/sites/1/automation-rules/7/runs");
    const log = await screen.findByText("Runs — Nightly rank watch");
    expect(log).toBeInTheDocument();
    // Trigger context summarized; failed runs show the error, both statuses render.
    expect(screen.getAllByText("manual=true").length).toBeGreaterThan(0);
    expect(screen.getAllByText("frequency=daily").length).toBeGreaterThan(0);
    expect(screen.getAllByText("no_recipient").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Success").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Failed").length).toBeGreaterThan(0);
  });

  it("deletes a rule only after confirmation", async () => {
    const user = userEvent.setup();
    render(<AutomationStudioPage />);

    await screen.findAllByText("Alert relay");
    // The second row's delete button belongs to "Alert relay".
    await user.click(screen.getAllByRole("button", { name: "Delete" })[1]);

    expect(globalThis.confirm).toHaveBeenCalledWith(
      'Delete automation rule "Alert relay"? Its run history is deleted with it.',
    );
    expect(apiMocks.apiDelete).toHaveBeenCalledWith("/sites/1/automation-rules/8");
    expect(await screen.findByText('Rule "Alert relay" deleted.')).toBeInTheDocument();
  });
});
