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

type ApiEnvelope<T> = {
  ok: boolean;
  data: T;
  meta?: unknown;
  code?: string;
  errors?: Record<string, string[]>;
};

export async function fetchKpis(signal?: AbortSignal): Promise<Kpis> {
  const res = await fetch('/api/dashboard/kpis', {
    method: 'GET',
    credentials: 'include',
    headers: { Accept: 'application/json' },
    signal,
  });

  if (res.status === 403) throw new Error('forbidden');

  const text = await res.text();
  if (!res.ok) throw new Error(text || `error ${res.status}`);

  // Parse once, then unwrap if envelope-shaped.
  const json: unknown = text ? JSON.parse(text) : null;

  if (json && typeof json === 'object' && 'ok' in json && 'data' in json) {
    const env = json as ApiEnvelope<Kpis>;
    if (env.ok !== true) {
      const detail =
        env.code ??
        (env.errors ? Object.keys(env.errors).map(k => `${k}: ${env.errors?.[k]?.join(', ')}`).join('; ') : '');
      throw new Error(detail || 'request_failed');
    }
    return env.data;
  }

  return json as Kpis;
}
