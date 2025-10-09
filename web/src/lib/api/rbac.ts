import { apiDelete, apiGet, apiPatch, apiPost, apiPut, HttpError } from "../api";
import { type RoleOptionInput } from "../roles";

export type RoleListResponse = {
  ok: boolean;
  roles: RoleOptionInput[];
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

export type UpdateRoleResult =
  | {
      kind: "updated";
      status: number;
      roleId: string;
      roleName: string;
      raw: unknown;
    }
  | {
      kind: "stub";
      status: number;
      acceptedName?: string;
      raw: unknown;
    }
  | {
      kind: "error";
      status: number;
      code?: string;
      message?: string;
      raw?: unknown;
    };

export type DeleteRoleResult =
  | { kind: "deleted"; status: number; raw: unknown }
  | { kind: "stub"; status: number; raw: unknown }
  | { kind: "error"; status: number; code?: string; message?: string; raw?: unknown };

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

function coerceUserSummary(u: unknown): UserSummary | null {
  if (!isObject(u)) return null;
  const id = Number(u.id ?? 0);
  const name = String(u.name ?? "");
  const email = String(u.email ?? "");
  if (!Number.isFinite(id) || id <= 0) return null;
  return { id, name, email };
}

function coerceErrorFromHttp(err: unknown): UserRolesResponseErr {
  if (err instanceof HttpError) {
    const body = err.body;
    const code = isObject(body) && typeof body.code === "string" ? body.code : "REQUEST_FAILED";
    const message = isObject(body) && typeof body.message === "string" ? body.message : undefined;
    return { ok: false, code, message };
  }
  return { ok: false, code: "NETWORK_ERROR" };
}

export async function listRoles(): Promise<RoleListResponse> {
  try {
    const json = await apiGet<unknown>("/api/rbac/roles");
    const j = isObject(json) ? (json as Record<string, unknown>) : {};

    const collected: RoleOptionInput[] = [];
    let foundArray = false;

    const append = (value: unknown): void => {
      if (!Array.isArray(value)) return;
      foundArray = true;
      collected.push(...value);
    };

    append(json);
    append(j.roles);
    append(j.data);

    if (!foundArray) {
      return { ok: false, roles: [], note: "invalid_response" };
    }

    const note = typeof j.note === "string" ? (j.note as string) : undefined;
    return { ok: true, roles: collected, note };
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
  } catch (err) {
    if (err instanceof HttpError) {
      const body = err.body;
      const code = isObject(body) && typeof body.code === "string" ? (body.code as string) : undefined;
      const baseMessage =
        isObject(body) && typeof body.message === "string" ? (body.message as string) : undefined;

      let detailedMessage: string | undefined;
      if (isObject(body?.errors)) {
        const errors = body.errors as Record<string, unknown>;
        for (const value of Object.values(errors)) {
          if (Array.isArray(value)) {
            const found = value.find((item): item is string => typeof item === "string" && item.trim() !== "");
            if (found) {
              detailedMessage = found;
              break;
            }
          }
        }
      }

      return {
        kind: "error",
        status: err.status,
        code,
        message: detailedMessage ?? baseMessage,
        raw: body,
      };
    }

    return { kind: "error", status: 0, code: "NETWORK_ERROR" };
  }
}

export async function updateRole(identifier: string, name: string): Promise<UpdateRoleResult> {
  try {
    const res = await apiPatch<unknown, { name: string }>(
      `/api/rbac/roles/${encodeURIComponent(identifier)}`,
      { name }
    );
    const j = isObject(res) ? (res as Record<string, unknown>) : {};

    if (j.ok === true && isObject(j.role)) {
      const role = j.role as Record<string, unknown>;
      const roleId = typeof role.id === "string" ? role.id : undefined;
      const roleName = typeof role.name === "string" ? role.name : undefined;
      if (roleId && roleName) {
        return { kind: "updated", status: 200, roleId, roleName, raw: res };
      }
    }

    const note = typeof j.note === "string" ? j.note : undefined;
    if (note === "stub-only") {
      const accepted = isObject(j.accepted) ? (j.accepted as Record<string, unknown>) : undefined;
      const acceptedName = accepted && typeof accepted.name === "string" ? accepted.name : undefined;
      return { kind: "stub", status: 202, acceptedName, raw: res };
    }

    const code = typeof j.code === "string" ? j.code : undefined;
    const message = typeof j.message === "string" ? j.message : undefined;
    return { kind: "error", status: typeof (j.status ?? 400) === "number" ? (j.status as number) : 400, code, message, raw: res };
  } catch (err) {
    if (err instanceof HttpError) {
      const body = err.body;
      const code = isObject(body) && typeof body.code === "string" ? body.code : undefined;
      const message = isObject(body) && typeof body.message === "string" ? body.message : undefined;
      return { kind: "error", status: err.status, code, message, raw: body ?? err };
    }
    return { kind: "error", status: 0, code: "NETWORK_ERROR" };
  }
}

export async function deleteRole(identifier: string): Promise<DeleteRoleResult> {
  try {
    const res = await apiDelete<unknown>(`/api/rbac/roles/${encodeURIComponent(identifier)}`);
    const j = isObject(res) ? (res as Record<string, unknown>) : {};

    if (j.ok === true) {
      return { kind: "deleted", status: 200, raw: res };
    }

    const note = typeof j.note === "string" ? j.note : undefined;
    if (note === "stub-only") {
      return { kind: "stub", status: 202, raw: res };
    }

    const code = typeof j.code === "string" ? j.code : undefined;
    const message = typeof j.message === "string" ? j.message : undefined;
    return { kind: "error", status: typeof (j.status ?? 400) === "number" ? (j.status as number) : 400, code, message, raw: res };
  } catch (err) {
    if (err instanceof HttpError) {
      const body = err.body;
      const code = isObject(body) && typeof body.code === "string" ? body.code : undefined;
      const message = isObject(body) && typeof body.message === "string" ? body.message : undefined;
      return { kind: "error", status: err.status, code, message, raw: body ?? err };
    }
    return { kind: "error", status: 0, code: "NETWORK_ERROR" };
  }
}

export async function getUserRoles(userId: number): Promise<UserRolesResponse> {
  try {
    const json = await apiGet<unknown>(`/api/rbac/users/${encodeURIComponent(String(userId))}/roles`);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    // Accept explicit ok or implicit shape
    if (j.ok === true) return json as UserRolesResponseOk;

    const user = coerceUserSummary(j.user);
    const roles = Array.isArray(j.roles) ? (j.roles as unknown[]).filter((r): r is string => typeof r === "string") : null;
    if (user && roles) return { ok: true, user, roles };

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch (e) {
    return coerceErrorFromHttp(e);
  }
}

export async function replaceUserRoles(userId: number, roles: string[]): Promise<UserRolesResponse> {
  try {
    const json = await apiPut<unknown, { roles: string[] }>(
      `/api/rbac/users/${encodeURIComponent(String(userId))}/roles`,
      { roles }
    );
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (j.ok === true) return json as UserRolesResponseOk;

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch (e) {
    return coerceErrorFromHttp(e);
  }
}

export async function attachUserRole(userId: number, roleId: string): Promise<UserRolesResponse> {
  try {
    const json = await apiPost<unknown>(
      `/api/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleId)}`
    );
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (j.ok === true) return json as UserRolesResponseOk;

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch (e) {
    return coerceErrorFromHttp(e);
  }
}

export async function detachUserRole(userId: number, roleId: string): Promise<UserRolesResponse> {
  try {
    const json = await apiDelete<unknown>(
      `/api/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleId)}`
    );
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (j.ok === true) return json as UserRolesResponseOk;

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch (e) {
    return coerceErrorFromHttp(e);
  }
}

/**
 * Search RBAC users with pagination.
 * Accepts responses with or without ok:true as long as data+meta are present.
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

    const data = Array.isArray(j.data) ? (j.data as unknown[]) : [];
    const parsedData: UserSummary[] = data
      .map(coerceUserSummary)
      .filter((u): u is UserSummary => !!u);

    const metaRaw = isObject(j.meta) ? (j.meta as Record<string, unknown>) : {};
    const meta: UserSearchMeta = {
      page: Number(metaRaw.page ?? p) || p,
      per_page: Number(metaRaw.per_page ?? pp) || pp,
      total: Number(metaRaw.total ?? parsedData.length) || 0,
      total_pages: Number(metaRaw.total_pages ?? 1) || 1,
    };

    const okLike = j.ok === true || (Array.isArray(j.data) && isObject(j.meta));
    if (okLike) {
      return { ok: true, data: parsedData, meta };
    }

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, status: 400, code, message, raw: json };
  } catch (e) {
    if (e instanceof HttpError) {
      const body = e.body;
      const code = isObject(body) && typeof body.code === "string" ? body.code : "REQUEST_FAILED";
      const message = isObject(body) && typeof body.message === "string" ? body.message : undefined;
      return { ok: false, status: e.status, code, message, raw: body };
    }
    return { ok: false, status: 0, code: "NETWORK_ERROR" };
  }
}
