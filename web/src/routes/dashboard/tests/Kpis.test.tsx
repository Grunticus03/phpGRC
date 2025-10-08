/** @vitest-environment jsdom */
import React from "react";
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

import Kpis from "../Kpis";

const KPIS_URL = "/api/dashboard/kpis";
const originalFetch = globalThis.fetch as typeof fetch;
const mockNavigate = vi.fn();

vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

vi.mock("react-chartjs-2", () => ({
  Bar: (props: { options?: { onClick?: (...args: unknown[]) => void } }) => (
    <button
      type="button"
      data-testid="bar-chart"
      onClick={() => props.options?.onClick?.({}, [{ index: 0 } as never])}
    >
      Bar Chart
    </button>
  ),
  Pie: (props: { data: unknown }) => (
    <div data-testid="pie-chart" data-data={JSON.stringify(props.data)}>
      Pie Chart
    </div>
  ),
}));

function json(body: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
    ...init,
  });
}

describe("Dashboard KPIs", () => {
  beforeEach(() => {
    mockNavigate.mockReset();
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);

      if (url.startsWith(KPIS_URL)) {
        return json({
          ok: true,
          data: {
            auth_activity: {
              window_days: 7,
              from: "2025-01-01T00:00:00Z",
              to: "2025-01-07T23:59:59Z",
              daily: [
                { date: "2025-01-01", success: 3, failed: 1, total: 4 },
                { date: "2025-01-02", success: 2, failed: 1, total: 3 },
              ],
              totals: { success: 5, failed: 2, total: 7 },
              max_daily_total: 4,
            },
            evidence_mime: {
              total: 5,
              by_mime: [
                { mime: "application/pdf", count: 3, percent: 0.6 },
                { mime: "image/png", count: 2, percent: 0.4 },
              ],
            },
            admin_activity: {
              admins: [
                {
                  id: 1,
                  name: "Alice Admin",
                  email: "alice@example.test",
                  last_login_at: "2025-01-07T12:00:00Z",
                },
                {
                  id: 2,
                  name: "Bob Admin",
                  email: "bob@example.test",
                  last_login_at: null,
                },
              ],
            },
          },
        });
      }

      return json({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("renders charts and admin table when data loads", async () => {
    render(
      <MemoryRouter>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    expect(screen.getByText(/Authentications last 7 days/i)).toBeInTheDocument();
    expect(screen.getByText(/Evidence MIME types/i)).toBeInTheDocument();
    expect(screen.getByText(/Admin Activity/i)).toBeInTheDocument();

    expect(screen.getByText("Alice Admin")).toBeInTheDocument();
    expect(screen.getByText("Bob Admin")).toBeInTheDocument();
    expect(screen.getByText("alice@example.test")).toBeInTheDocument();
  });

  it("navigates to audit view when bar chart clicked", async () => {
    render(
      <MemoryRouter>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    fireEvent.click(screen.getByTestId("bar-chart"));

    expect(mockNavigate).toHaveBeenCalledWith(
      expect.stringContaining("/admin/audit?category=AUTH")
    );
    const target = mockNavigate.mock.calls[0][0] as string;
    expect(target).toContain("occurred_from=2025-01-01");
    expect(target).toContain("occurred_to=2025-01-01");
  });

  it("shows forbidden message on 403", async () => {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      if (url.startsWith(KPIS_URL)) {
        return json({ ok: false, code: "FORBIDDEN" }, { status: 403 });
      }
      return json({ ok: true });
    }) as unknown as typeof fetch;

    render(
      <MemoryRouter>
        <Kpis />
      </MemoryRouter>
    );

    await screen.findByText(/access to KPIs/i);
  });
});
