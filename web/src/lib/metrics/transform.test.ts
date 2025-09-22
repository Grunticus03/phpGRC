import { describe, it, expect } from "vitest";
import { toSparkPointsFromDenies, clampWindows, windowLabel, sparkAriaLabel } from "./transform";
import type { RbacDenies, Kpis } from "../api/metrics";

describe("metrics transform", () => {
  it("maps daily denies to spark points", () => {
    const d: RbacDenies = {
      window_days: 3,
      from: "2025-09-18",
      to: "2025-09-20",
      denies: 3,
      total: 30,
      rate: 0.1,
      daily: [
        { date: "2025-09-18", denies: 1, total: 10, rate: 0.1 },
        { date: "2025-09-19", denies: 1, total: 10, rate: 0.1 },
        { date: "2025-09-20", denies: 1, total: 10, rate: 0.1 },
      ],
    };
    const pts = toSparkPointsFromDenies(d);
    expect(pts).toEqual([
      { x: "2025-09-18", y: 1 },
      { x: "2025-09-19", y: 1 },
      { x: "2025-09-20", y: 1 },
    ]);
  });

  it("clamps windows", () => {
    expect(clampWindows({ rbac_days: -5, days: 999 })).toEqual({ rbac_days: 1, days: 365 });
    expect(clampWindows({})).toEqual({ rbac_days: undefined, days: undefined });
  });

  it("labels windows and aria", () => {
    const kpis: Kpis = {
      rbac_denies: { window_days: 7, from: "", to: "", denies: 0, total: 0, rate: 0, daily: [] },
      evidence_freshness: { days: 30, total: 0, stale: 0, percent: 0, by_mime: [] },
    };
    expect(windowLabel(kpis)).toBe("RBAC 7d • Evidence 30d");
    expect(sparkAriaLabel(kpis.rbac_denies)).toContain("RBAC denies over 7 days");
  });
});
