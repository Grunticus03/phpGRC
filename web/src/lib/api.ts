export const API_BASE = String(import.meta.env.VITE_API_BASE || "/api").replace(/\/+$/, "");

export class HttpError extends Error {
  status: number;
  body: unknown;
  constructor(status: number, body: unknown, message?: string) {
    super(message ?? `HTTP ${status}`);
    this.status = status;
    this.body = body;
  }
}

type QueryInit = Record<string, string | number | boolean | null | undefined>;

function qs(params?: QueryInit): string {
  if (!params) return "";
  const sp = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null) continue;
    const val = typeof v === "boolean" ? (v ? "true" : "false") : String(v);
    sp.set(k, val);
  }
  const s = sp.toString();
  return s ? `?${s}` : "";
}

function toUrl(path: string, params?: QueryInit): string {
  const p = path.startsWith("/") ? path : `/${path}`;
  return `${API_BASE}${p}${qs(params)}`;
}

async function handle<T>(res: Response): Promise<T> {
  const ct = res.headers.get("content-type") || "";
  const isJson = ct.includes("application/json") || ct.includes("+json");
  const body = isJson ? await res.json().catch(() => null) : await res.text().catch(() => null);
  if (!res.ok) throw new HttpError(res.status, body);
  return body as T;
}

export async function apiGet<T>(path: string, params?: QueryInit, signal?: AbortSignal): Promise<T> {
  const url = toUrl(path, params);
  const res = await fetch(url, { method: "GET", credentials: "same-origin", signal });
  return handle<T>(res);
}
