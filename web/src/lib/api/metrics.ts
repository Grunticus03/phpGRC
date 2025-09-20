export type RbacDeniesDaily = {
  date: string;        // YYYY-MM-DD
  denies: number;
  total: number;
  rate: number;        // 0..1
};

export type RbacDenies = {
  window_days: number;
  from: string;        // ISO date
  to: string;          // ISO date
  denies: number;
  total: number;
  rate: number;        // 0..1
  daily: RbacDeniesDaily[];
};

export type EvidenceByMime = {
  mime: string;
  total: number;
  stale: number;
  percent: number;     // 0..100
};

export type EvidenceFreshness = {
  days: number;
  total: number;
  stale: number;
  percent: number;     // 0..100
  by_mime: EvidenceByMime[];
};

export type Kpis = {
  rbac_denies: RbacDenies;
  evidence_freshness: EvidenceFreshness;
};

export async function fetchKpis(signal?: AbortSignal): Promise<Kpis> {
  const res = await fetch('/api/dashboard/kpis', {
    method: 'GET',
    credentials: 'include',
    headers: { 'Accept': 'application/json' },
    signal,
  });

  if (res.status === 403) {
    throw new Error('forbidden');
  }
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(text || `error ${res.status}`);
  }

  return res.json() as Promise<Kpis>;
}
