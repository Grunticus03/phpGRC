/* eslint-disable @typescript-eslint/consistent-type-definitions */

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

type JsonLike = Record<string, unknown> | readonly unknown[] | string | number | boolean | null | undefined;

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

async function request<TResp, TReq = unknown>(
  method: Method,
  path: string,
  opts?: {
    params?: QueryInit;
    body?: TReq;
    signal?: AbortSignal;
    headers?: Record<string, string>;
  }
): Promise<TResp> {
  const url = toUrl(path, opts?.params);
  const init: RequestInit = {
    method,
    credentials: "same-origin",
    signal: opts?.signal,
    headers: { ...(opts?.headers ?? {}) },
  };

  const body = opts?.body as unknown;

  if (body instanceof FormData || body instanceof URLSearchParams) {
    init.body = body;
    // Let the browser set the correct multipart/urlencoded headers.
  } else if (body !== undefined) {
    // Treat as JSON-like
    init.body = JSON.stringify(body as JsonLike);
    (init.headers as Record<string, string>)["Content-Type"] = "application/json";
  }

  const res = await fetch(url, init);
  return handle<TResp>(res);
}

export async function apiGet<T>(path: string, params?: QueryInit, signal?: AbortSignal): Promise<T> {
  return request<T>("GET", path, { params, signal });
}

export async function apiPost<TResp, TReq = unknown>(
  path: string,
  body?: TReq,
  signal?: AbortSignal
): Promise<TResp> {
  return request<TResp, TReq>("POST", path, { body, signal });
}

export async function apiPut<TResp, TReq = unknown>(
  path: string,
  body?: TReq,
  signal?: AbortSignal
): Promise<TResp> {
  return request<TResp, TReq>("PUT", path, { body, signal });
}

export async function apiPatch<TResp, TReq = unknown>(
  path: string,
  body?: TReq,
  signal?: AbortSignal
): Promise<TResp> {
  return request<TResp, TReq>("PATCH", path, { body, signal });
}

export async function apiDelete<TResp>(path: string, signal?: AbortSignal): Promise<TResp> {
  return request<TResp>("DELETE", path, { signal });
}

export async function apiUpload<TResp>(
  path: string,
  form: FormData,
  signal?: AbortSignal
): Promise<TResp> {
  return request<TResp>("POST", path, { body: form, signal });
}
