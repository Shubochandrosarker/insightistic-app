import { expect, test, type Page, type Route } from "@playwright/test";

const session = {
  user: {
    id: 42,
    name: "Campaign Owner",
    email: "owner@example.com",
    is_super_admin: false,
  },
  organizations: [{ id: 7, name: "Insightistic", slug: "insightistic", role: "owner" }],
};

async function fulfillSupportingRequest(route: Route) {
  const path = new URL(route.request().url()).pathname;

  if (path.endsWith("/api/sites")) {
    await route.fulfill({ status: 200, json: { sites: [] } });
    return;
  }

  if (path.endsWith("/api/auth/oauth/providers")) {
    await route.fulfill({ status: 200, json: { providers: ["google", "github"] } });
    return;
  }

  if (path.endsWith("/api/brand-settings")) {
    await route.fulfill({ status: 200, json: { brand_settings: null } });
    return;
  }

  await route.fulfill({ status: 200, json: {} });
}

async function token(page: Page) {
  return page.evaluate(() => localStorage.getItem("ins_token"));
}

test("a transient failure in a second tab preserves and recovers the shared session", async ({ context }) => {
  const primary = await context.newPage();

  await primary.route("**/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/api/auth/me")) {
      await route.fulfill({ status: 200, json: session });
      return;
    }
    await fulfillSupportingRequest(route);
  });

  await primary.goto("/login");
  await primary.evaluate(() => localStorage.setItem("ins_token", "shared-session-token"));
  await primary.reload();

  await expect(primary).toHaveURL(/\/dashboard$/);
  await expect(primary.getByRole("heading", { name: "Add your first website" })).toBeVisible();

  const secondary = await context.newPage();
  let secondaryAuthAttempts = 0;
  let originUnavailable = true;

  await secondary.route("**/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/api/auth/me")) {
      secondaryAuthAttempts += 1;
      if (originUnavailable) {
        await route.fulfill({
          status: 520,
          json: { message: "Web server is returning an unknown error" },
        });
        return;
      }
      await route.fulfill({ status: 200, json: session });
      return;
    }
    await fulfillSupportingRequest(route);
  });

  await secondary.goto("/dashboard");

  await expect(
    secondary.getByRole("heading", { name: "We could not check your session" }),
  ).toBeVisible();
  expect(secondaryAuthAttempts).toBeGreaterThanOrEqual(3);
  expect(await token(primary)).toBe("shared-session-token");
  expect(await token(secondary)).toBe("shared-session-token");

  originUnavailable = false;
  await secondary.getByRole("button", { name: "Try again" }).click();

  await expect(secondary.getByRole("heading", { name: "Add your first website" })).toBeVisible();
  expect(secondaryAuthAttempts).toBeGreaterThanOrEqual(4);
  expect(await token(primary)).toBe("shared-session-token");
  expect(await token(secondary)).toBe("shared-session-token");
});
