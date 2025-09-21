export type RbacDaily = {
  date: string;          // YYYY-MM-DD
  denies: number;
  total: number;
  rate: number;          // 0..1
};

export type RbacDenies = {
  window_days: number;
  from: string;          // YYYY-MM-DD
  to: string;            // YYYY-MM-DD
  denies: number;
  total: number;
  rate: number;          // 0..1
  daily: RbacDaily[];
};

export type EvidenceByMime = {
  mime: string;
  total: number;
  stale: number;
  percent: number;       // 0..100 (normalized here)
};

export type EvidenceFreshness = {
  days: number;
  total: number;
  stale: number;
  percent: number;       // 0..100 (normalized here)
  by_mime: EvidenceByMime[];
};

export type Kpis = {
  rbac_denies: RbacDenies;
  evidence_freshness: EvidenceFreshness;
};

type RawEvidenceByMime = Omit<EvidenceByMime, "percent"> & { percent: number }; // 0..1 or 0..100
type RawEvidenceFreshness = Omit<EvidenceFreshness, "percent" | "by_mime"> & {
  percent: number;        // 0..1 or 0..100
  by_mime: RawEvidenceByMime[];
};

type RawRbacDenies = RbacDenies; // already 0..1 rate

function toPct(n: unknown): number {
  const v = typeof n === "number" && isFinite(n) ? n : 0;
  const pct = v <= 1 ? v * 100 : v;
  return Math.max(0, Math.min(100, pct));
}

function normalize(raw: unknown): Kpis {
  const root = (raw && typeof raw === "object" ? (raw as Record<string, unknown>) : {}) as {
    rbac_denies?: RawRbacDenies;
    evidence_freshness?: RawEvidenceFreshness;
  };

  const rd = root.rbac_denies as RawRbacDenies;
  const ev = root.evidence_freshness as RawEvidenceFreshness;

  const evidence: EvidenceFreshness = {
    days: Number(ev?.days ?? 30),
    total: Number(ev?.total ?? 0),
    stale: Number(ev?.stale ?? 0),
    percent: toPct(ev?.percent ?? 0),
    by_mime: Array.isArray(ev?.by_mime)
      ? (ev!.by_mime as RawEvidenceByMime[]).map((row) => ({
          mime: String(row.mime ?? ""),
          total: Number(row.total ?? 0),
          stale: Number(row.stale ?? 0),
          percent: toPct(row.percent ?? 0),
        }))
      : [],
  };

  const denies: RbacDenies = {
    window_days: Number(rd?.window_days ?? 7),
    from: String(rd?.from ?? ""),
    to: String(rd?.to ?? ""),
    denies: Number(rd?.denies ?? 0),
    total: Number(rd?.total ?? 0),
    rate: Math.max(0, Math.min(1, Number(rd?.rate ?? 0))),
    daily: Array.isArray(rd?.daily)
      ? rd!.daily.map((d) => ({
          date: String((d as RbacDaily).date ?? ""),
          denies: Number((d as RbacDaily).denies ?? 0),
          total: Number((d as RbacDaily).total ?? 0),
          rate: Math.max(0, Math.min(1, Number((d as RbacDaily).rate ?? 0))),
        }))
      : [],
  };

  return { rbac_denies: denies, evidence_freshness: evidence };
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && String(v).length > 0) qs.set(k, String(v));
  });
  const s = qs.toString();
  return s ? `?${s}` : "";
}

/**
 * Fetch KPI snapshot from /api/dashboard/kpis.
 * Optional params: { rbac_days, days }.
 * Returns normalized KPIs with percent fields scaled to 0..100 for UI.
 * Throws Error('forbidden') on 403.
 */
export async function fetchKpis(signal?: AbortSignal, opts?: { rbac_days?: number; days?: number }): Promise<Kpis> {
  const query = buildQuery({
    rbac_days: typeof opts?.rbac_days === "number" ? Math.max(1, Math.min(365, Math.trunc(opts.rbac_days))) : undefined,
    days: typeof opts?.days === "number" ? Math.max(1, Math.min(365, Math.trunc(opts.days))) : undefined,
  });

  const res = await fetch(`/api/dashboard/kpis${query}`, {
    credentials: "same-origin",
    signal,
  });

  if (res.status === 403) throw new Error("forbidden");
  if (!res.ok) throw new Error(`http_${res.status}`);

  const json = (await res.json()) as unknown;

  // Accept either envelope { ok, data } or raw object
  const data =
    json && typeof json === "object" && "data" in (json as Record<string, unknown>)
      ? (json as Record<string, unknown>)["data"]
      : json;

  return normalize(data as unknown);
}
