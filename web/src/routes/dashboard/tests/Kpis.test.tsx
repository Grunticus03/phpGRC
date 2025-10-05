/** @vitest-environment jsdom */
import React from "react";
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import Kpis from "../Kpis";

const originalFetch = globalThis.fetch as typeof fetch;
const KPIS_URL = "/api/dashboard/kpis";

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

      if (url.startsWith(KPIS_URL)) {
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
              percent: 0.5,
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

    expect(screen.getByLabelText("deny-rate").textContent).toContain("25.0%");
    expect(screen.getByLabelText("stale-percent").textContent).toContain("50.0%");
    expect(screen.getByText("application/pdf")).toBeInTheDocument();
    expect(screen.getByText("image/png")).toBeInTheDocument();
  });

  it("shows forbidden message on 403", async () => {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      if (url.startsWith(KPIS_URL)) {
        return json({ ok: false, code: "FORBIDDEN" }, { status: 403 });
      }
      return json({ ok: true });
    }) as unknown as typeof fetch;

    render(<Kpis />);
    await screen.findByText(/access to KPIs/i);
  });

  it("applies rbac_days and days in query when submitting", async () => {
    const calls: string[] = [];
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      if (url.startsWith(KPIS_URL)) {
        calls.push(url);
        return json({
          ok: true,
          data: {
            rbac_denies: {
              window_days: 14,
              from: "2025-01-01",
              to: "2025-01-14",
              denies: 10,
              total: 40,
              rate: 0.25,
              daily: [],
            },
            evidence_freshness: { days: 45, total: 10, stale: 5, percent: 0.5, by_mime: [] },
          },
        });
      }
      return json({ ok: true });
    }) as unknown as typeof fetch;

    render(<Kpis />);

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    const rbac = screen.getByLabelText("RBAC window (days)") as HTMLInputElement;
    const fresh = screen.getByLabelText("Evidence stale threshold (days)") as HTMLInputElement;

    fireEvent.change(rbac, { target: { value: "14" } });
    fireEvent.change(fresh, { target: { value: "45" } });

    fireEvent.click(screen.getByRole("button", { name: "Apply" }));

    await waitFor(() => {
      const last = calls[calls.length - 1] || "";
      const u = new URL(last, "http://localhost");
      expect(u.pathname).toBe("/api/dashboard/kpis");
      expect(u.searchParams.get("rbac_days")).toBe("14");
      expect(u.searchParams.get("days")).toBe("45");
    });

    expect((screen.getByLabelText("RBAC window (days)") as HTMLInputElement).value).toBe("14");
    expect((screen.getByLabelText("Evidence stale threshold (days)") as HTMLInputElement).value).toBe("45");
  });
});
