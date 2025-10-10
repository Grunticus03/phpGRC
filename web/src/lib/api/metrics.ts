import { apiGet } from "../api";

export type AuthDaily = {
  date: string; // YYYY-MM-DD
  success: number;
  failed: number;
  total: number;
};

export type AuthActivity = {
  window_days: number;
  from: string; // ISO8601
  to: string;   // ISO8601
  daily: AuthDaily[];
  totals: { success: number; failed: number; total: number };
  max_daily_total: number;
};

export type EvidenceMimeSlice = {
  mime: string;
  mime_label: string;
  count: number;
  percent: number; // 0..100 (normalized)
};

export type EvidenceMime = {
  total: number;
  by_mime: EvidenceMimeSlice[];
};

export type AdminActivityRow = {
  id: number;
  name: string;
  email: string;
  last_login_at: string | null;
};

export type AdminActivity = {
  admins: AdminActivityRow[];
};

export type Kpis = {
  auth_activity: AuthActivity;
  evidence_mime: EvidenceMime;
  admin_activity: AdminActivity;
};

type RawAuthDaily = Partial<AuthDaily>;
type RawAuthActivity = Partial<Omit<AuthActivity, "daily" | "totals">> & {
  daily?: RawAuthDaily[];
  totals?: Partial<AuthActivity["totals"]>;
};

type RawEvidenceSlice = Partial<EvidenceMimeSlice>;
type RawEvidenceMime = Partial<Omit<EvidenceMime, "by_mime">> & { by_mime?: RawEvidenceSlice[] };

type RawAdminRow = Partial<AdminActivityRow>;
type RawAdminActivity = Partial<Omit<AdminActivity, "admins">> & { admins?: RawAdminRow[] };

type RawKpis = {
  auth_activity?: RawAuthActivity;
  evidence_mime?: RawEvidenceMime;
  admin_activity?: RawAdminActivity;
};

function toPct(value: unknown): number {
  const v = typeof value === "number" && Number.isFinite(value) ? value : 0;
  const pct = v <= 1 ? v * 100 : v;
  return Math.max(0, Math.min(100, pct));
}

function normalize(raw: unknown): Kpis {
  const root: RawKpis = raw && typeof raw === "object" ? (raw as RawKpis) : {};

  const authRaw = root.auth_activity ?? {};
  const auth: AuthActivity = {
    window_days: Number.isFinite(authRaw.window_days) ? Number(authRaw.window_days) : 7,
    from: typeof authRaw.from === "string" ? authRaw.from : "",
    to: typeof authRaw.to === "string" ? authRaw.to : "",
    daily: Array.isArray(authRaw.daily)
      ? authRaw.daily.map((row) => ({
          date: typeof row.date === "string" ? row.date : "",
          success: Number.isFinite(row.success) ? Number(row.success) : 0,
          failed: Number.isFinite(row.failed) ? Number(row.failed) : 0,
          total: Number.isFinite(row.total) ? Number(row.total) : 0,
        }))
      : [],
    totals: {
      success: Number.isFinite(authRaw.totals?.success) ? Number(authRaw.totals?.success) : 0,
      failed: Number.isFinite(authRaw.totals?.failed) ? Number(authRaw.totals?.failed) : 0,
      total: Number.isFinite(authRaw.totals?.total) ? Number(authRaw.totals?.total) : 0,
    },
    max_daily_total: Number.isFinite(authRaw.max_daily_total) ? Number(authRaw.max_daily_total) : 0,
  };

  const evidenceRaw = root.evidence_mime ?? {};
  const evidence: EvidenceMime = {
    total: Number.isFinite(evidenceRaw.total) ? Number(evidenceRaw.total) : 0,
    by_mime: Array.isArray(evidenceRaw.by_mime)
      ? evidenceRaw.by_mime.map((row) => {
          const mime = typeof row.mime === "string" && row.mime.length > 0 ? row.mime : "Unknown";
          const label =
            typeof row.mime_label === "string" && row.mime_label.length > 0
              ? row.mime_label
              : mime;
          return {
            mime,
            mime_label: label,
            count: Number.isFinite(row.count) ? Number(row.count) : 0,
            percent: toPct(row.percent ?? 0),
          };
        })
      : [],
  };

  const adminRaw = root.admin_activity ?? {};
  const admin: AdminActivity = {
    admins: Array.isArray(adminRaw.admins)
      ? adminRaw.admins.map((row) => ({
          id: Number.isFinite(row.id) ? Number(row.id) : 0,
          name: typeof row.name === "string" ? row.name : "",
          email: typeof row.email === "string" ? row.email : "",
          last_login_at:
            typeof row.last_login_at === "string" && row.last_login_at.length > 0
              ? row.last_login_at
              : null,
        }))
      : [],
  };

  return { auth_activity: auth, evidence_mime: evidence, admin_activity: admin };
}

export type FetchKpisOptions = {
  auth_days?: number;
};

/**
 * Fetch KPI snapshot from /api/dashboard/kpis.
 * Throws Error('forbidden') on 403.
 */
export async function fetchKpis(signal?: AbortSignal, opts?: FetchKpisOptions): Promise<Kpis> {
  const authDays = typeof opts?.auth_days === "number" ? Math.trunc(opts.auth_days) : undefined;
  const clampedAuthDays = authDays !== undefined ? Math.max(7, Math.min(365, authDays)) : undefined;

  const params = clampedAuthDays !== undefined ? { auth_days: clampedAuthDays } : undefined;

  const json = await apiGet<unknown>('/api/dashboard/kpis', params, signal);

  const data =
    json && typeof json === "object" && "data" in (json as Record<string, unknown>)
      ? (json as Record<string, unknown>).data
      : json;

  return normalize(data);
}
