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

async function parseJson(res: Response): Promise<any> {
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
    if (json && typeof json === "object" && Array.isArray(json.roles)) {
      return { ok: true, roles: json.roles, note: typeof json.note === "string" ? json.note : undefined };
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

    if (res.status === 201 && json?.ok === true && json?.role?.id && json?.role?.name) {
      return {
        kind: "created",
        status: res.status,
        roleId: String(json.role.id),
        roleName: String(json.role.name),
        raw: json,
      };
    }

    if ((res.status === 202 || res.status === 200 || res.status === 400) && json?.note === "stub-only") {
      const accepted = json?.accepted?.name ?? name;
      return { kind: "stub", status: res.status, acceptedName: String(accepted), raw: json };
    }

    if (res.status === 422) {
      return { kind: "error", status: res.status, code: "VALIDATION_FAILED", raw: json };
    }
    if (res.status === 403) {
      return { kind: "error", status: res.status, code: "FORBIDDEN", raw: json };
    }

    return { kind: "error", status: res.status, code: json?.code, raw: json };
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
    if (res.ok && json?.ok) {
      return json as UserRolesResponseOk;
    }
    return { ok: false, code: json?.code ?? "REQUEST_FAILED", message: json?.message };
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
    if (res.ok && json?.ok) {
      return json as UserRolesResponseOk;
    }
    return { ok: false, code: json?.code ?? "REQUEST_FAILED", message: json?.message };
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
    if (res.ok && json?.ok) {
      return json as UserRolesResponseOk;
    }
    return { ok: false, code: json?.code ?? "REQUEST_FAILED", message: json?.message };
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
    if (res.ok && json?.ok) {
      return json as UserRolesResponseOk;
    }
    return { ok: false, code: json?.code ?? "REQUEST_FAILED", message: json?.message };
  } catch {
    return { ok: false, code: "NETWORK_ERROR" };
  }
}
