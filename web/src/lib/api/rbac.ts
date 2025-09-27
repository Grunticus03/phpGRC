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

async function parseJson(res: Response): Promise<unknown> {
  try {
    return await res.json();
  } catch {
    return null;
  }
}

function clamp(n: number, min: number, max: number): number {
  if (!Number.isFinite(n)) return min;
  if (n < min) return min;
  if (n > max) return max;
  return n;
}

export async function listRoles(): Promise<RoleListResponse> {
  try {
    const res = await fetch("/api/rbac/roles", { credentials: "same-origin" });
    const json = await parseJson(res);

    const roles =
      isObject(json) && Array.isArray(json.roles) && json.roles.every((r) => typeof r === "string")
        ? (json.roles as string[])
        : [];

    if (roles.length > 0) {
      const note = isObject(json) && typeof json.note === "string" ? (json.note as string) : undefined;
      return { ok: true, roles, note };
    }

    return { ok: false, roles: [], note: "invalid_response" };
  } catch {
    return { ok: false, roles: [], note: "network_error" };
  }
}

export async function createRole(name: string): Promise<CreateRoleResult> {
  try {
    const res = await fetch("/api/rbac/roles", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ name }),
    });
    const json = await parseJson(res);

    const j = isObject(json) ? (json as Record<string, unknown>) : {};

    // 201 Created
    const roleRaw = j.role;
    const role = isObject(roleRaw) ? (roleRaw as Record<string, unknown>) : undefined;
    const roleId = role && role.id !== undefined ? String(role.id) : undefined;
    const roleName = role && role.name !== undefined ? String(role.name) : undefined;
    const okVal = j.ok === true;

    if (res.status === 201 && okVal && roleId && roleName) {
      return {
        kind: "created",
        status: res.status,
        roleId,
        roleName,
        raw: json,
      };
    }

    // stub-only acceptance
    const note = typeof j.note === "string" ? (j.note as string) : undefined;
    if ((res.status === 202 || res.status === 200 || res.status === 400) && note === "stub-only") {
      const acc = j.accepted;
      let acceptedName = name;
      if (isObject(acc)) {
        const nm = (acc as Record<string, unknown>).name;
        if (typeof nm === "string") acceptedName = nm;
      }
      return { kind: "stub", status: res.status, acceptedName, raw: json };
    }

    if (res.status === 422) {
      return { kind: "error", status: res.status, code: "VALIDATION_FAILED", raw: json };
    }
    if (res.status === 403) {
      return { kind: "error", status: res.status, code: "FORBIDDEN", raw: json };
    }

    const code = typeof j.code === "string" ? (j.code as string) : undefined;
    return { kind: "error", status: res.status, code, raw: json };
  } catch {
    return { kind: "error", status: 0, code: "NETWORK_ERROR" };
  }
}

export async function getUserRoles(userId: number): Promise<UserRolesResponse> {
  try {
    const res = await fetch(`/api/rbac/users/${encodeURIComponent(String(userId))}/roles`, {
      credentials: "same-origin",
    });
    const json = await parseJson(res);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}

export async function replaceUserRoles(userId: number, roles: string[]): Promise<UserRolesResponse> {
  try {
    const res = await fetch(`/api/rbac/users/${encodeURIComponent(String(userId))}/roles`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ roles }),
    });
    const json = await parseJson(res);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}

export async function attachUserRole(userId: number, roleName: string): Promise<UserRolesResponse> {
  try {
    const res = await fetch(
      `/api/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleName)}`,
      { method: "POST", credentials: "same-origin" }
    );
    const json = await parseJson(res);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}

export async function detachUserRole(userId: number, roleName: string): Promise<UserRolesResponse> {
  try {
    const res = await fetch(
      `/api/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleName)}`,
      { method: "DELETE", credentials: "same-origin" }
    );
    const json = await parseJson(res);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
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

    const url = new URL("/api/rbac/users/search", window.location.origin);
    if (q) url.searchParams.set("q", q);
    url.searchParams.set("page", String(p));
    url.searchParams.set("per_page", String(pp));

    const res = await fetch(url.toString().replace(window.location.origin, ""), { credentials: "same-origin" });
    const json = await parseJson(res);
    const j = isObject(json) ? (json as Record<string, unknown>) : {};

    // Accept both {ok:true,data,meta} and bare {data,meta}
    const okFlag = j.ok === true || (!("ok" in j) && res.ok);

    const data = Array.isArray(j.data) ? (j.data as unknown[]) : [];

    const parsedData: UserSummary[] = data
      .map((u) => (isObject(u) ? (u as Record<string, unknown>) : null))
      .filter((u): u is Record<string, unknown> => !!u)
      .map((u) => ({
        id: Number(u.id ?? 0),
        name: String(u.name ?? ""),
        email: String(u.email ?? ""),
      }))
      .filter((u) => Number.isFinite(u.id) && u.id > 0 && u.name.length >= 0 && u.email.length >= 0);

    const metaRaw = isObject(j.meta) ? (j.meta as Record<string, unknown>) : {};
    const meta: UserSearchMeta = {
      page: Number(metaRaw.page ?? page ?? 1) || 1,
      per_page: Number(metaRaw.per_page ?? perPage ?? 50) || 50,
      total: Number(metaRaw.total ?? parsedData.length) || 0,
      total_pages: Number(metaRaw.total_pages ?? 1) || 1,
    };

    if (okFlag) {
      return { ok: true, data: parsedData, meta };
    }

    const code = typeof j.code === "string" ? (j.code as string) : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? (j.message as string) : undefined;
    return { ok: false, status: res.status, code, message, raw: json };
  } catch {
    return { ok: false, status: 0, code: "NETWORK_ERROR" };
  }
}
