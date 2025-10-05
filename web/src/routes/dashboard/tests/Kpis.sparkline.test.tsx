/* FILE: web/src/routes/dashboard/tests/Kpis.sparkline.test.tsx */
import { describe, it, beforeEach, afterEach, expect, vi } from "vitest";
import { render, screen, waitFor, cleanup } from "@testing-library/react";
import React from "react";
import Kpis from "../Kpis";

const okPayload = {
  ok: true,
  data: {
    rbac_denies: {
      window_days: 7,
      from: "2025-09-01",
      to: "2025-09-07",
      denies: 7,
      total: 28,
      rate: 0.25,
      daily: [
        { date: "2025-09-01", denies: 1, total: 4, rate: 0.25 },
        { date: "2025-09-02", denies: 0, total: 2, rate: 0.0 },
        { date: "2025-09-03", denies: 2, total: 6, rate: 0.3333 },
        { date: "2025-09-04", denies: 1, total: 5, rate: 0.2 },
        { date: "2025-09-05", denies: 0, total: 3, rate: 0.0 },
        { date: "2025-09-06", denies: 2, total: 4, rate: 0.5 },
        { date: "2025-09-07", denies: 1, total: 4, rate: 0.25 },
      ],
    },
    evidence_freshness: {
      days: 30,
      total: 10,
      stale: 5,
      percent: 50,
      by_mime: [{ mime: "application/pdf", total: 6, stale: 3, percent: 50 }],
    },
  },
};

describe("Dashboard KPIs sparkline", () => {
  beforeEach(() => {
    const responseLike = {
      ok: true,
      status: 200,
      headers: new Headers({ "content-type": "application/json" }),
      json: async () => okPayload,
      text: async () => JSON.stringify(okPayload),
    } as unknown as Response;

    const mock = vi.fn(async () => responseLike) as unknown as typeof fetch;
    vi.stubGlobal("fetch", mock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    cleanup();
  });

  it("renders an SVG path for the RBAC denies sparkline", async () => {
    render(<Kpis />);

    await screen.findByLabelText("deny-rate");
    const svg = await screen.findByLabelText("RBAC denies sparkline");
    expect(svg.tagName.toLowerCase()).toBe("svg");

    await waitFor(() => {
      const path = svg.querySelector("path");
      expect(path).not.toBeNull();
      expect(path?.getAttribute("d") ?? "").toContain("L");
    });
  });
});
