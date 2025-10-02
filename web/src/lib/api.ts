// Centralized API helpers (no .env required)

/** Prefix for API routes. Keep empty string to use same-origin relative paths. */
export const API_BASE = "";

/** Query object for building URLs. */
export type QueryInit = Record<string, string | number | boolean | null | undefined>;

const TOKEN_KEY = "phpgrc_auth_token";

/** Read bearer token from localStorage (if present). */
export function getToken(): string | null {
  try {
    const raw = localStorage.getItem(TOKEN_KEY);
    return raw && raw.trim() ? raw : null;
  } catch {
    return null;
  }
}

/** True if a bearer token is currently stored. */
export function hasToken(): boolean {
  return !!getToken();
}

/** Write/remove bearer token in localStorage. */
export function setToken(token: string | null): void {
  try {
    if (token && token.trim()) localStorage.setItem(TOKEN_KEY, token.trim());
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    // ignore storage errors
  }
}

/** Merge default headers with optional extras; attach Authorization if token exists. */
export function baseHeaders(extra?: HeadersInit): HeadersInit {
  const h: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  const tok = getToken();
  if (tok) h.Authorization = `Bearer ${tok}`;
  return { ...h, ...(extra as Record<string, string>) };
}

/** Build query string from a plain object. */
export function qs(params?: QueryInit): string {
  if (!params) return "";
  const sp = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null) continue;
    sp.set(k, typeof v === "boolean" ? (v ? "true" : "false") : String(v));
  }
  const s = sp.toString();
  return s ? `?${s}` : "";
}

/** Error object thrown for non-2xx responses, with parsed body attached. */
export class HttpError<TBody = unknown> extends Error {
  status: number;
  body: TBody | null;
  constructor(status: number, body: TBody | null, message?: string) {
    super(message ?? `HTTP ${status}`);
    this.status = status;
    this.body = body;
  }
}

/** Safely parse a response body as JSON or text; returns null on failure. */
async function parseBody(res: Response): Promise<unknown> {
  const ct = res.headers.get("content-type") || "";
  const isJson = ct.includes("application/json") || ct.includes("+json");
  try {
    return isJson ? await res.json() : await res.text();
  } catch {
    return null;
  }
}

/* ----------------------- 401 notification plumbing ----------------------- */
export type UnauthorizedHandler = (err: HttpError<unknown>) => void;

const unauthHandlers = new Set<UnauthorizedHandler>();

/** Register a callback for 401s coming from api* helpers. Returns an unsubscribe. */
export function onUnauthorized(fn: UnauthorizedHandler): () => void {
  unauthHandlers.add(fn);
  return () => unauthHandlers.delete(fn);
}

function notifyUnauthorized(err: HttpError<unknown>): void {
  unauthHandlers.forEach((fn) => {
    try {
      fn(err);
    } catch {
      // ignore handler errors
    }
  });
}
/* ------------------------------------------------------------------------ */

/** GET helper that throws HttpError on non-OK and returns typed payload on success. */
export async function apiGet<TResponse = unknown>(
  path: string,
  params?: QueryInit,
  signal?: AbortSignal
): Promise<TResponse> {
  const p = path.startsWith("/") ? path : `/${path}`;
  const url = `${API_BASE}${p}${qs(params)}`;
  const res = await fetch(url, { method: "GET", credentials: "same-origin", headers: baseHeaders(), signal });
  const body = (await parseBody(res)) as TResponse | null;
  if (!res.ok) {
    const err = new HttpError(res.status, body);
    if (res.status === 401) notifyUnauthorized(err);
    throw err;
  }
  return (body as TResponse) ?? ({} as TResponse);
}

/** PUT helper that throws HttpError on non-OK and returns typed payload on success. */
export async function apiPut<TResponse = unknown, TBody = unknown>(
  path: string,
  body?: TBody,
  signal?: AbortSignal
): Promise<TResponse> {
  const p = path.startsWith("/") ? path : `/${path}`;
  const url = `${API_BASE}${p}`;
  const res = await fetch(url, {
    method: "PUT",
    credentials: "same-origin",
    headers: baseHeaders({ "Content-Type": "application/json" }),
    body: body === undefined ? "{}" : JSON.stringify(body),
    signal,
  });
  const parsed = (await parseBody(res)) as TResponse | null;
  if (!res.ok) {
    const err = new HttpError(res.status, parsed);
    if (res.status === 401) notifyUnauthorized(err);
    throw err;
  }
  return (parsed as TResponse) ?? ({} as TResponse);
}

/** POST helper mirroring apiPut. */
export async function apiPost<TResponse = unknown, TBody = unknown>(
  path: string,
  body?: TBody,
  signal?: AbortSignal
): Promise<TResponse> {
  const p = path.startsWith("/") ? path : `/${path}`;
  const url = `${API_BASE}${p}`;
  const res = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: baseHeaders({ "Content-Type": "application/json" }),
    body: body === undefined ? "{}" : JSON.stringify(body),
    signal,
  });
  const parsed = (await parseBody(res)) as TResponse | null;
  if (!res.ok) {
    const err = new HttpError(res.status, parsed);
    if (res.status === 401) notifyUnauthorized(err);
    throw err;
  }
  return (parsed as TResponse) ?? ({} as TResponse);
}

/** DELETE helper returning typed payload. */
export async function apiDelete<TResponse = unknown>(
  path: string,
  signal?: AbortSignal
): Promise<TResponse> {
  const p = path.startsWith("/") ? path : `/${path}`;
  const url = `${API_BASE}${p}`;
  const res = await fetch(url, { method: "DELETE", credentials: "same-origin", headers: baseHeaders(), signal });
  const parsed = (await parseBody(res)) as TResponse | null;
  if (!res.ok) {
    const err = new HttpError(res.status, parsed);
    if (res.status === 401) notifyUnauthorized(err);
    throw err;
  }
  return (parsed as TResponse) ?? ({} as TResponse);
}

/** Store and consume the user's intended path around auth flows. */
const INTENDED_KEY = "phpgrc_intended_path";

export function rememberIntendedPath(pathname: string): void {
  try {
    if (pathname && pathname !== "/auth/login") sessionStorage.setItem(INTENDED_KEY, pathname);
  } catch {
    // ignore
  }
}

export function consumeIntendedPath(): string | null {
  try {
    const v = sessionStorage.getItem(INTENDED_KEY);
    if (v) sessionStorage.removeItem(INTENDED_KEY);
    return v;
  } catch {
    return null;
  }
}

/** Login helper: stores token if returned by API. */
export async function authLogin(creds: { email: string; password: string }): Promise<void> {
  type LoginResp = { ok?: boolean; token?: string; access_token?: string };
  const resp = await apiPost<LoginResp, typeof creds>("/api/auth/login", creds);
  const tok = (resp && (resp.token || resp.access_token)) ?? null;
  if (tok) setToken(tok);
}

/** Current-user helper. Throws on non-OK (401 will trigger onUnauthorized handlers). */
export async function authMe<TUser = unknown>(): Promise<TUser> {
  // Adjust the path here if your backend exposes a different endpoint (e.g., /api/me).
  return apiGet<TUser>("/api/auth/me");
}
