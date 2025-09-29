export const API_BASE = String(import.meta.env.VITE_API_BASE ?? "").replace(/\/+$/, "");

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

/** POST overloads: allow calling with a single type arg for the response. */
export function apiPost<TRes>(path: string, data?: unknown, signal?: AbortSignal): Promise<TRes>;
export function apiPost<TReq extends object, TRes>(path: string, data: TReq, signal?: AbortSignal): Promise<TRes>;
export async function apiPost(path: string, data?: unknown, signal?: AbortSignal): Promise<unknown> {
  const url = toUrl(path);
  const res = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: { "content-type": "application/json" },
    body: data === undefined ? "{}" : JSON.stringify(data),
    signal,
  });
  return handle(res);
}

/** PATCH overloads: allow calling with a single type arg for the response. */
export function apiPatch<TRes>(path: string, data?: unknown, signal?: AbortSignal): Promise<TRes>;
export function apiPatch<TReq extends object, TRes>(path: string, data: TReq, signal?: AbortSignal): Promise<TRes>;
export async function apiPatch(path: string, data?: unknown, signal?: AbortSignal): Promise<unknown> {
  const url = toUrl(path);
  const res = await fetch(url, {
    method: "PATCH",
    credentials: "same-origin",
    headers: { "content-type": "application/json" },
    body: data === undefined ? "{}" : JSON.stringify(data),
    signal,
  });
  return handle(res);
}

export async function apiPut<TReq extends object, TRes>(
  path: string,
  data: TReq,
  signal?: AbortSignal
): Promise<TRes> {
  const url = toUrl(path);
  const res = await fetch(url, {
    method: "PUT",
    credentials: "same-origin",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(data),
    signal,
  });
  return handle<TRes>(res);
}

export async function apiDelete<TRes>(path: string, signal?: AbortSignal): Promise<TRes> {
  const url = toUrl(path);
  const res = await fetch(url, { method: "DELETE", credentials: "same-origin", signal });
  return handle<TRes>(res);
}

/** Auth helpers */
export interface LoginRequest {
  email: string;
  password: string;
  otp?: string;
}
export type JsonObject = Record<string, unknown>;

export function authLogin<T = JsonObject>(creds: LoginRequest, signal?: AbortSignal): Promise<T> {
  return apiPost<LoginRequest, T>("/auth/login", creds, signal);
}

export function authLogout<T = JsonObject>(signal?: AbortSignal): Promise<T> {
  return apiPost<T>("/auth/logout", {}, signal);
}

export function authMe<T = JsonObject>(signal?: AbortSignal): Promise<T> {
  return apiGet<T>("/auth/me", undefined, signal);
}
