import { apiDelete, apiGet, apiPost, apiPut } from "../api";

export type RoleListResponse = {
  ok: boolean;
  roles: string[];
  note?: string;
};

export type CreateRoleCreated = {
  kind: "created";
  status: number;
  roleId: string;
  roleName: string;
  raw: unknown;
};

export type CreateRoleStub = {
  kind: "stub";
  status: number;
  acceptedName: string;
  raw: unknown;
};

export type CreateRoleError = {
  kind: "error";
  status: number;
  code?: string;
  message?: string;
  raw?: unknown;
};

export type CreateRoleResult = CreateRoleCreated | CreateRoleStub | CreateRoleError;

export type UserSummary = { id: number; name: string; email: string };

export type UserRolesResponseOk = {
  ok: true;
  user: UserSummary;
  roles: string[];
};

export type UserRolesResponseErr = {
  ok: false;
  code: string;
  message?: string;
};

export type UserRolesResponse = UserRolesResponseOk | UserRolesResponseErr;

export type UserSearchMeta = {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
};

export type UserSearchOk = {
  ok: true;
  data: UserSummary[];
  meta: UserSearchMeta;
};

export type UserSearchErr = {
  ok: false;
  status: number;
  code: string;
  message?: string;
  raw?: unknown;
};

export type UserSearchResult = UserSearchOk | UserSearchErr;

function isObject(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === "object";
}

function clamp(n: number, min: number, max: number): number {
  if (!Number.isFinite(n)) return min;
  if (n < min) return min;
  if (n > max) return max;
  return n;
}

export async function listRoles(): Promise<RoleListResponse> {
  try {
    const json = await apiGet<unknown>("/api/rbac/roles");
    const j = isObject(json) ? (json as Record<string, unknown>) : {};

    const rolesFromArray = Array.isArray(json) ? (json as unknown[]) : null;
    const rolesFromProp =
      Array.isArray(j.roles) ? (j.roles as unknown[]) :
      Array.isArray(j.data) ? (j.data as unknown[]) : null;

    const raw = (rolesFromArray ?? rolesFromProp ?? []).filter((r): r is string => typeof r === "string");

    if (raw.length > 0 || (Array.isArray(rolesFromProp) && rolesFromProp.length === 0)) {
      const note = typeof j.note === "string" ? (j.note as string) : undefined;
      return { ok: true, roles: raw, note };
    }

    return { ok: false, roles: [], note: "invalid_response" };
  } catch {
    return { ok: false, roles: [], note: "network_error" };
  }
}

export async function createRole(name: string): Promise<CreateRoleResult> {
  try {
    const res = await apiPost<unknown, { name: string }>("/api/rbac/roles", { name });
    const j = isObject(res) ? (res as Record<string, unknown>) : {};

    const roleRaw = j.role;
    const role = isObject(roleRaw) ? (roleRaw as Record<string, unknown>) : undefined;
    const roleId = role && role.id !== undefined ? String(role.id) : undefined;
    const roleName = role && role.name !== undefined ? String(role.name) : undefined;
    const okVal = j.ok === true;

    // Treat presence of role object as created
    if (okVal && roleId && roleName) {
      return { kind: "created", status: 201, roleId, roleName, raw: res };
    }

    const note = typeof j.note === "string" ? (j.note as string) : undefined;
    if (note === "stub-only") {
      const acc = j.accepted;
      let acceptedName = name;
      if (isObject(acc)) {
        const nm = (acc as Record<string, unknown>).name;
        if (typeof nm === "string") acceptedName = nm;
      }
      return { kind: "stub", status: 202, acceptedName, raw: res };
    }

    const code = typeof j.code === "string" ? (j.code as string) : undefined;
    return { kind: "error", status: 400, code, raw: res };
  } catch {
    return { kind: "error", status: 0, code: "NETWORK_ERROR" };
  }
}

export async function getUserRoles(userId: number): Promise<UserRolesResponse> {
  try {
    const json = await apiGet<unknown>(`/api/rbac/users/${encodeURIComponent(String(userId))}/roles`);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (j.ok === true) return json as UserRolesResponseOk;
    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}

export async function replaceUserRoles(userId: number, roles: string[]): Promise<UserRolesResponse> {
  try {
    const json = await apiPut<unknown, { roles: string[] }>(`/api/rbac/users/${encodeURIComponent(String(userId))}/roles`, { roles });
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (j.ok === true) return json as UserRolesResponseOk;
    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}

export async function attachUserRole(userId: number, roleName: string): Promise<UserRolesResponse> {
  try {
    const json = await apiPost<unknown>(`/api/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleName)}`);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (j.ok === true) return json as UserRolesResponseOk;
    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}

export async function detachUserRole(userId: number, roleName: string): Promise<UserRolesResponse> {
  try {
    const json = await apiDelete<unknown>(`/api/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleName)}`);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (j.ok === true) return json as UserRolesResponseOk;
    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}

/**
 * Search RBAC users with pagination.
 * Server clamps per_page to [1,500]. Default assumed 50.
 */
export async function searchUsers(
  q: string,
  page: number = 1,
  perPage: number = 50
): Promise<UserSearchResult> {
  try {
    const p = clamp(Number(page) || 1, 1, Number.MAX_SAFE_INTEGER);
    const pp = clamp(Number(perPage) || 50, 1, 500);

    const json = await apiGet<unknown>("/api/rbac/users/search", { q, page: p, per_page: pp });
    const j = isObject(json) ? (json as Record<string, unknown>) : {};

    const okFlag = j.ok === true;

    const data = Array.isArray(j.data) ? (j.data as unknown[]) : [];
    const parsedData: UserSummary[] = data
      .map((u) => (isObject(u) ? (u as Record<string, unknown>) : null))
      .filter((u): u is Record<string, unknown> => !!u)
      .map((u) => ({
        id: Number(u.id ?? 0),
        name: String(u.name ?? ""),
        email: String(u.email ?? ""),
      }))
      .filter((u) => Number.isFinite(u.id) && u.id > 0);

    const metaRaw = isObject(j.meta) ? (j.meta as Record<string, unknown>) : {};
    const meta: UserSearchMeta = {
      page: Number(metaRaw.page ?? p) || p,
      per_page: Number(metaRaw.per_page ?? pp) || pp,
      total: Number(metaRaw.total ?? parsedData.length) || 0,
      total_pages: Number(metaRaw.total_pages ?? 1) || 1,
    };

    if (okFlag) {
      return { ok: true, data: parsedData, meta };
    }

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, status: 400, code, message, raw: json };
  } catch {
    return { ok: false, status: 0, code: "NETWORK_ERROR" };
  }
}
