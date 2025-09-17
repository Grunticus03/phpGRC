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

    const j = isObject(json) ? json : {};

    // 201 Created
    const role = isObject(j.role) ? j.role : undefined;
    const roleId = isObject(role) && "id" in role ? String(role.id) : undefined;
    const roleName = isObject(role) && "name" in role ? String(role.name) : undefined;
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
    const note = typeof j.note === "string" ? j.note : undefined;
    if ((res.status === 202 || res.status === 200 || res.status === 400) && note === "stub-only") {
      const accepted = isObject(j.accepted) && "name" in j.accepted ? String(j.accepted.name) : name;
      return { kind: "stub", status: res.status, acceptedName: accepted, raw: json };
    }

    if (res.status === 422) {
      return { kind: "error", status: res.status, code: "VALIDATION_FAILED", raw: json };
    }
    if (res.status === 403) {
      return { kind: "error", status: res.status, code: "FORBIDDEN", raw: json };
    }

    const code = typeof j.code === "string" ? j.code : undefined;
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
    const j = isObject(json) ? json : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
    const code = typeof j.code === "string" ? j.code : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? j.message : undefined;
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
    const j = isObject(json) ? json : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
    const code = typeof j.code === "string" ? j.code : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? j.message : undefined;
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
    const j = isObject(json) ? json : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
    const code = typeof j.code === "string" ? j.code : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? j.message : undefined;
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
    const j = isObject(json) ? json : {};
    if (res.ok && j.ok === true) {
      return json as UserRolesResponseOk;
    }
    const code = typeof j.code === "string" ? j.code : "REQUEST_FAILED";
    const message = typeof j.message === "string" ? j.message : undefined;
    return { ok: false, code, message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}
