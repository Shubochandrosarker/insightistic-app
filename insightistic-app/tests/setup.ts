import "@testing-library/jest-dom/vitest";
import { vi, afterEach } from "vitest";
import { cleanup } from "@testing-library/react";

/**
 * Next's router and search params are runtime-provided; in jsdom they have to
 * be stubbed or every component that navigates throws on import.
 */
export const routerMock = {
  push: vi.fn(),
  replace: vi.fn(),
  refresh: vi.fn(),
  back: vi.fn(),
};

let searchParams = new URLSearchParams();

export function setSearchParams(query: string) {
  searchParams = new URLSearchParams(query);
}

vi.mock("next/navigation", () => ({
  useRouter: () => routerMock,
  useSearchParams: () => searchParams,
  usePathname: () => "/dashboard",
}));

afterEach(() => {
  cleanup();
  searchParams = new URLSearchParams();
});
