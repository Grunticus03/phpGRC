export type Evidence = {
  id: string;
  owner_id: number;
  filename: string;
  mime: string;
  size: number;
  sha256: string;
  version: number;
  created_at: string;
};

export type EvidenceListOk = {
  ok: true;
  data: Evidence[];
  next_cursor: string | null;
  filters?: Record<string, unknown>;
};

export type EvidenceListErr = {
  ok: false;
  status: number;
  code: string;
  message?: string;
  raw?: unknown;
};

export type EvidenceListResult = EvidenceListOk | EvidenceListErr;

function isObject(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === "object";
}

async function parseJson(res: Response): Promise<unknown> {
  try {
    return await res.json();
  } catch {
    return null;
  }
}

export type EvidenceListParams = {
  owner_id?: number;
  filename?: string;
  mime?: string;
  sha256?: string;
  sha256_prefix?: string;
  version_from?: number;
  version_to?: number;
  created_from?: string; // ISO
  created_to?: string;   // ISO
  order?: "asc" | "desc";
  limit?: number;        // 1..100
  cursor?: string | null;
};

export async function listEvidence(params: EvidenceListParams = {}): Promise<EvidenceListResult> {
  try {
    const url = new URL("/api/evidence", window.location.origin);
    Object.entries(params).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      url.searchParams.set(k, String(v));
    });

    const res = await fetch(url.toString().replace(window.location.origin, ""), { credentials: "same-origin" });
    const json = await parseJson(res);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};

    if (res.ok && j.ok === true && Array.isArray(j.data)) {
      const data = (j.data as unknown[])
        .map((it) => (isObject(it) ? (it as Record<string, unknown>) : null))
        .filter((r): r is Record<string, unknown> => !!r)
        .map((r) => ({
          id: String(r.id ?? ""),
          owner_id: Number(r.owner_id ?? 0),
          filename: String(r.filename ?? ""),
          mime: String(r.mime ?? ""),
          size: Number(r.size ?? 0),
          sha256: String(r.sha256 ?? ""),
          version: Number(r.version ?? 0),
          created_at: String(r.created_at ?? ""),
        }))
        .filter((e) => e.id && Number.isFinite(e.owner_id));

      const next_cursor =
        typeof j.next_cursor === "string" ? (j.next_cursor as string) : j.next_cursor === null ? null : null;

      return { ok: true, data, next_cursor, filters: isObject(j.filters) ? (j.filters as Record<string, unknown>) : undefined };
    }

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, status: res.status, code, message, raw: json };
  } catch {
    return { ok: false, status: 0, code: "NETWORK_ERROR" };
  }
}

