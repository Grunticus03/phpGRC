export const API_BASE = String(import.meta.env.VITE_API_BASE ?? "/api").replace(/\/+$/, "");

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

export async function apiPost<T>(path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
  const url = toUrl(path);
  const res = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? null : JSON.stringify(body),
    signal,
  });
  return handle<T>(res);
}

export async function apiPut<T>(path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
  const url = toUrl(path);
  const res = await fetch(url, {
    method: "PUT",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? null : JSON.stringify(body),
    signal,
  });
  return handle<T>(res);
}

export async function apiDelete<T>(path: string, signal?: AbortSignal): Promise<T> {
  const url = toUrl(path);
  const res = await fetch(url, { method: "DELETE", credentials: "same-origin", signal });
  return handle<T>(res);
}

/** Auth helpers */
export interface LoginRequest {
  email: string;
  password: string;
}
export interface LoginResponse {
  ok: boolean;
  token?: string;
  [k: string]: unknown;
}
export interface MeResponse {
  ok: boolean;
  user?: { id: number; name: string; email: string } | null;
  [k: string]: unknown;
}

export function authLogin(payload: LoginRequest) {
  return apiPost<LoginResponse>("/auth/login", payload);
}
export function authLogout() {
  return apiPost<{ ok: boolean }>("/auth/logout", {});
}
export function authMe() {
  return apiGet<MeResponse>("/auth/me");
}

/** Admin Users */
export interface User {
  id: number;
  name: string;
  email: string;
}
export interface UsersIndexResponse {
  ok: boolean;
  data: User[];
  meta: { page: number; per_page: number; total: number; total_pages: number };
}
export interface UserCreatePayload {
  name: string;
  email: string;
  password: string;
  roles?: string[];
}
export interface UserUpdatePayload {
  name?: string;
  email?: string;
  password?: string;
  roles?: string[];
}
export function adminUsersIndex(params: { q?: string; page?: number; per_page?: number }) {
  return apiGet<UsersIndexResponse>("/admin/users", params);
}
export function adminUsersStore(payload: UserCreatePayload) {
  return apiPost<{ ok: boolean; user: User }>("/admin/users", payload);
}
export function adminUsersUpdate(id: number, payload: UserUpdatePayload) {
  return apiPut<{ ok: boolean; user: User }>(`/admin/users/${id}`, payload);
}
export function adminUsersDelete(id: number) {
  return apiDelete<{ ok: boolean }>(`/admin/users/${id}`);
}