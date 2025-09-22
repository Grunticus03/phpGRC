import type { RbacDenies, RbacDaily, Kpis } from "../api/metrics";

/**
 * Convert RBAC daily series to sparkline points using denies count.
 */
export function toSparkPointsFromDenies(denies: RbacDenies): { x: string; y: number }[] {
  const daily = Array.isArray(denies?.daily) ? denies.daily : [];
  return daily.map((d: RbacDaily) => ({ x: String(d.date ?? ""), y: Number(d.denies ?? 0) }));
}

/**
 * Clamp and normalize UI-facing day windows, retaining server-side defaults when undefined.
 */
export function clampWindows(opts?: { rbac_days?: number; days?: number }): { rbac_days?: number; days?: number } {
  const clamp = (n: unknown) => {
    const v = typeof n === "number" && isFinite(n) ? Math.trunc(n) : undefined;
    if (v === undefined) return undefined;
    return Math.max(1, Math.min(365, v));
  };
  return { rbac_days: clamp(opts?.rbac_days), days: clamp(opts?.days) };
}

/**
 * Compose a human label for the current KPI windows.
 */
export function windowLabel(kpis: Kpis): string {
  const r = kpis?.rbac_denies?.window_days;
  const e = kpis?.evidence_freshness?.days;
  return `RBAC ${r}d • Evidence ${e}d`;
}

/**
 * Compute a brief textual summary for screen readers.
 */
export function sparkAriaLabel(denies: RbacDenies): string {
  const n = denies?.daily?.length ?? 0;
  const last = n > 0 ? denies.daily[n - 1].denies : 0;
  return `RBAC denies over ${denies?.window_days ?? 0} days. Last day ${last}.`;
}
