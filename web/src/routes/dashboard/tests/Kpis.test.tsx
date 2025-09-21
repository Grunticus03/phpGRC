/** @vitest-environment jsdom */
import React from "react";
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import Kpis from "../Kpis";

const originalFetch = globalThis.fetch as typeof fetch;

function json(body: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
    ...init,
  });
}

describe("Dashboard KPIs", () => {
  beforeEach(() => {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);

      if (url === "/api/dashboard/kpis") {
        return json({
          ok: true,
          data: {
            rbac_denies: {
              window_days: 7,
              from: "2025-01-01",
              to: "2025-01-07",
              denies: 7,
              total: 28,
              rate: 0.25,
              daily: [
                { date: "2025-01-01", denies: 1, total: 4, rate: 0.25 },
                { date: "2025-01-02", denies: 1, total: 4, rate: 0.25 },
                { date: "2025-01-03", denies: 1, total: 4, rate: 0.25 },
                { date: "2025-01-04", denies: 1, total: 4, rate: 0.25 },
                { date: "2025-01-05", denies: 1, total: 4, rate: 0.25 },
                { date: "2025-01-06", denies: 1, total: 4, rate: 0.25 },
                { date: "2025-01-07", denies: 1, total: 4, rate: 0.25 },
              ],
            },
            evidence_freshness: {
              days: 30,
              total: 10,
              stale: 5,
              percent: 0.5, // API may return 0..1; UI normalizes to %
              by_mime: [
                { mime: "application/pdf", total: 4, stale: 2, percent: 0.5 },
                { mime: "image/png", total: 6, stale: 3, percent: 0.5 },
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

  it("renders KPI cards and MIME table", async () => {
    render(<Kpis />);

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    // RBAC denies rate shows 25.0%
    expect(screen.getByLabelText("deny-rate").textContent).toContain("25.0%");

    // Evidence freshness shows 50.0%
    expect(screen.getByLabelText("stale-percent").textContent).toContain("50.0%");

    // MIME rows
    expect(screen.getByText("application/pdf")).toBeInTheDocument();
    expect(screen.getByText("image/png")).toBeInTheDocument();
  });

  it("shows forbidden message on 403", async () => {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      if (url === "/api/dashboard/kpis") {
        return json({ ok: false, code: "FORBIDDEN" }, { status: 403 });
      }
      return json({ ok: true });
    }) as unknown as typeof fetch;

    render(<Kpis />);

    await screen.findByText("You do not have access to KPIs.");
  });
});
