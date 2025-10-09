import { API_BASE, apiDelete, apiGet, apiPostFormData, baseHeaders, HttpError, qs } from "../api";
import { normalizeTimeFormat, type TimeFormat } from "../formatters";

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
  time_format?: TimeFormat;
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

function parseFilename(contentDisposition: string | null): string | null {
  if (!contentDisposition) return null;

  const starMatch = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(contentDisposition);
  if (starMatch?.[1]) {
    try {
      return decodeURIComponent(starMatch[1].replace(/(^"|"$)/g, ""));
    } catch {
      return starMatch[1].replace(/(^"|"$)/g, "");
    }
  }

  const quotedMatch = /filename="([^"]+)"/i.exec(contentDisposition);
  if (quotedMatch?.[1]) {
    return quotedMatch[1];
  }

  const plainMatch = /filename=([^;]+)/i.exec(contentDisposition);
  if (plainMatch?.[1]) {
    return plainMatch[1].trim().replace(/(^"|"$)/g, "");
  }

  return null;
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

export type EvidenceDownloadResult = {
  blob: Blob;
  filename: string;
  mime: string;
};

function buildEvidenceUrl(id: string, sha256?: string): string {
  const encoded = encodeURIComponent(id);
  const q = qs(sha256 ? { sha256 } : undefined);
  return `${API_BASE}/api/evidence/${encoded}${q}`;
}

export async function fetchEvidenceFile(id: string, sha256?: string): Promise<EvidenceDownloadResult> {
  const url = buildEvidenceUrl(id, sha256);
  const res = await fetch(url, {
    method: "GET",
    credentials: "same-origin",
    headers: baseHeaders({ Accept: "application/octet-stream" }),
  });
  const contentType = res.headers.get("content-type") ?? "";

  if (!res.ok) {
    let body: unknown = null;
    const ct = contentType.toLowerCase();
    try {
      if (ct.includes("application/json") || ct.includes("+json")) {
        body = await res.json();
      } else {
        body = await res.text();
      }
    } catch {
      body = null;
    }
    throw new HttpError(res.status, body);
  }

  const blob = await res.blob();
  const filename = parseFilename(res.headers.get("content-disposition")) ?? id;
  const mime = contentType !== "" ? contentType : "application/octet-stream";
  return { blob, filename, mime };
}

export async function downloadEvidenceFile(
  evidence: Pick<Evidence, "id" | "filename" | "sha256">
): Promise<void> {
  if (typeof window === "undefined" || typeof document === "undefined") return;

  const fallbackName = evidence.filename?.trim() !== "" ? evidence.filename.trim() : evidence.id;
  const { blob, filename } = await fetchEvidenceFile(evidence.id, evidence.sha256);
  if (typeof URL.createObjectURL === "function") {
    const href = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = href;
    anchor.download = filename?.trim() !== "" ? filename : fallbackName;
    anchor.rel = "noopener noreferrer";
    anchor.style.display = "none";
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(() => {
      URL.revokeObjectURL(href);
    }, 1000);
  } else if (typeof window.open === "function") {
    window.open(buildEvidenceUrl(evidence.id, evidence.sha256), "_blank", "noopener");
  } else {
    window.location.assign(buildEvidenceUrl(evidence.id, evidence.sha256));
  }
}

export async function listEvidence(params: EvidenceListParams = {}): Promise<EvidenceListResult> {
  try {
    const json = await apiGet<unknown>("/api/evidence", params);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};

    if (j.ok === true && Array.isArray(j.data)) {
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

      const nextTimeFormat = typeof (j.time_format as unknown) === 'string'
        ? normalizeTimeFormat(j.time_format)
        : undefined;

      return {
        ok: true,
        data,
        next_cursor,
        filters: isObject(j.filters) ? (j.filters as Record<string, unknown>) : undefined,
        time_format: nextTimeFormat,
      };
    }

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, status: 400, code, message, raw: json };
  } catch {
    return { ok: false, status: 0, code: "NETWORK_ERROR" };
  }
}

export type EvidenceUploadResponse = {
  ok: true;
  id: string;
  version: number;
  sha256: string;
  size: number;
  mime: string;
  name: string;
};

export async function uploadEvidence(file: File, signal?: AbortSignal): Promise<EvidenceUploadResponse> {
  const form = new FormData();
  form.append("file", file);
  const json = await apiPostFormData<unknown>("/api/evidence", form, signal);
  if (!isObject(json) || json.ok !== true) {
    throw new Error("Invalid response from upload endpoint");
  }

  const idValue = typeof json.id === "string" ? json.id : "";
  if (!idValue) {
    throw new Error("Missing id in upload response");
  }

  const versionRaw = (json as Record<string, unknown>).version;
  const sizeRaw = (json as Record<string, unknown>).size ?? (json as Record<string, unknown>).size_bytes;
  const shaValue = typeof json.sha256 === "string" ? json.sha256 : "";
  const mimeValue = typeof json.mime === "string" ? json.mime : "";
  const nameValue = typeof json.name === "string" ? json.name : "";

  const versionValue = Number(versionRaw ?? 0);
  const sizeValue = Number(sizeRaw ?? 0);

  return {
    ok: true,
    id: idValue,
    version: Number.isNaN(versionValue) ? 0 : versionValue,
    sha256: shaValue,
    size: Number.isNaN(sizeValue) ? 0 : sizeValue,
    mime: mimeValue,
    name: nameValue,
  };
}

export type EvidenceDeleteResponse = {
  ok: true;
  id: string;
  deleted: boolean;
};

export async function deleteEvidence(id: string, signal?: AbortSignal): Promise<EvidenceDeleteResponse> {
  const json = await apiDelete<unknown>(`/api/evidence/${encodeURIComponent(id)}`, signal);
  if (!isObject(json) || json.ok !== true) {
    throw new Error("Invalid response from delete endpoint");
  }

  const idValue = typeof json.id === "string" && json.id ? json.id : id;
  const deletedRaw = (json as Record<string, unknown>).deleted;

  return {
    ok: true,
    id: idValue,
    deleted: deletedRaw === undefined ? true : Boolean(deletedRaw),
  };
}
