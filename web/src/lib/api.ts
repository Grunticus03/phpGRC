/** Prefix for API routes. Keep empty string to use same-origin relative paths. */
export const API_BASE = "";

/** Query object for building URLs. */
export type QueryInit = Record<string, string | number | boolean | null | undefined>;

const INTENDED_KEY = "phpgrc_intended_path";
const SESSION_EXPIRED_KEY = "phpgrc_session_expired";
const TOKEN_STORAGE_KEY = "phpgrc_auth_token";

let cachedAuthToken: string | null | undefined;

function tokenStorage(): Storage | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

function readStoredToken(): string | null {
  const store = tokenStorage();
  if (!store) return null;
  try {
    const value = store.getItem(TOKEN_STORAGE_KEY);
    return value && value.length > 0 ? value : null;
  } catch {
    return null;
  }
}

export function getAuthToken(): string | null {
  if (cachedAuthToken !== undefined) return cachedAuthToken;
  cachedAuthToken = readStoredToken();
  return cachedAuthToken;
}

export function hasAuthToken(): boolean {
  const token = getAuthToken();
  return typeof token === "string" && token.length > 0;
}

function rememberAuthToken(token: string): void {
  cachedAuthToken = token;
  const store = tokenStorage();
  if (!store) return;
  try {
    store.setItem(TOKEN_STORAGE_KEY, token);
  } catch {
    // ignore persistence failures
  }
}

function forgetAuthToken(): void {
  cachedAuthToken = null;
  const store = tokenStorage();
  if (!store) return;
  try {
    store.removeItem(TOKEN_STORAGE_KEY);
  } catch {
    // ignore persistence failures
  }
}

/** Merge default headers with optional extras. */
export function baseHeaders(extra?: HeadersInit): HeadersInit {
  const h: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  const token = getAuthToken();
  if (token) h.Authorization = `Bearer ${token}`;
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
  forgetAuthToken();
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

/** PATCH helper mirroring apiPut. */
export async function apiPatch<TResponse = unknown, TBody = unknown>(
  path: string,
  body?: TBody,
  signal?: AbortSignal
): Promise<TResponse> {
  const p = path.startsWith("/") ? path : `/${path}`;
  const url = `${API_BASE}${p}`;
  const res = await fetch(url, {
    method: "PATCH",
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

/** POST FormData helper (no explicit Content-Type). */
export async function apiPostFormData<TResponse = unknown>(
  path: string,
  form: FormData,
  signal?: AbortSignal
): Promise<TResponse> {
  const p = path.startsWith("/") ? path : `/${path}`;
  const url = `${API_BASE}${p}`;
  const res = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: baseHeaders(), // do NOT set Content-Type; browser sets boundary
    body: form,
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

export function peekIntendedPath(): string | null {
  try {
    return sessionStorage.getItem(INTENDED_KEY);
  } catch {
    return null;
  }
}

/** Session-expired one-shot banner flag. */
export function markSessionExpired(): void {
  try {
    sessionStorage.setItem(SESSION_EXPIRED_KEY, "1");
  } catch {
    // ignore
  }
}

export function consumeSessionExpired(): boolean {
  try {
    const v = sessionStorage.getItem(SESSION_EXPIRED_KEY);
    if (v) sessionStorage.removeItem(SESSION_EXPIRED_KEY);
    return v === "1";
  } catch {
    return false;
  }
}

export type AuthUser = { id: number; email: string; roles: string[] };

export type AuthOptionsIdpProvider = {
  id: string;
  key: string;
  name: string;
  driver: string;
  links?: {
    authorize?: string | null;
  };
};

export type AuthOptionsAutoRedirect = {
  provider: string;
  key: string;
  driver: string;
  authorize?: string | null;
};

export type AuthOptions = {
  ok: boolean;
  mode: "none" | "local_only" | "idp_only" | "mixed";
  local: {
    enabled: boolean;
    mfa: {
      totp: {
        required_for_admin: boolean;
      };
    };
  };
  idp: {
    providers: AuthOptionsIdpProvider[];
  };
  auto_redirect: AuthOptionsAutoRedirect | null;
};

export async function fetchAuthOptions(signal?: AbortSignal): Promise<AuthOptions> {
  return apiGet<AuthOptions>("/auth/options", undefined, signal);
}

type LoginResponse = { ok: boolean; token?: string | null; user?: AuthUser | null };
type MeResponse = { ok: boolean; user?: AuthUser | null };

/** Login helper; persists returned token for subsequent API calls. */
export async function authLogin(creds: { email: string; password: string }): Promise<AuthUser> {
  const res = await apiPost<LoginResponse, typeof creds>("/auth/login", creds);
  const token = res?.token;
  const user = res?.user;

  if (!token || typeof token !== "string") {
    throw new Error("Missing auth token in login response");
  }

  if (!user || typeof user.id === "undefined") {
    throw new Error("Missing user payload in login response");
  }

  rememberAuthToken(token);

  return user;
}

type OidcLoginPayload = {
  provider: string;
  code?: string;
  id_token?: string;
  state?: string;
  redirect_uri: string;
};

export async function authOidcLogin(payload: OidcLoginPayload): Promise<AuthUser> {
  const res = await apiPost<LoginResponse, OidcLoginPayload>("/auth/oidc/login", payload);
  const token = res?.token;
  const user = res?.user;

  if (!token || typeof token !== "string") {
    throw new Error("Missing auth token in OIDC login response");
  }

  if (!user || typeof user.id === "undefined") {
    throw new Error("Missing user payload in OIDC login response");
  }

  rememberAuthToken(token);

  return user;
}

/** Logout helper: server best-effort then local clear. */
export async function authLogout(): Promise<void> {
  try {
    await apiPost<unknown, Record<string, never>>("/auth/logout", {});
  } catch {
    // ignore network/401 during logout
  }

  forgetAuthToken();
  try {
    sessionStorage.removeItem(INTENDED_KEY);
    sessionStorage.removeItem(SESSION_EXPIRED_KEY);
  } catch {
    // ignore
  }
}

/** Current-user helper. Throws on non-OK (401 will trigger onUnauthorized handlers). */
export async function authMe(): Promise<AuthUser> {
  const res = await apiGet<MeResponse>("/auth/me");
  const user = res?.user;
  if (!user || typeof user.id === "undefined") {
    throw new Error("Missing user payload in auth/me response");
  }
  return user;
}
