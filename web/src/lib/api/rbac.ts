import { apiDelete, apiGet, apiPatch, apiPost, apiPut, HttpError } from "../api";

const LOCAL_ROLES_DEFAULT = ["Admin", "Auditor", "User"];
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

export type PolicyAssignment = {
  policy: string;
  label: string | null;
  description: string | null;
  roles: string[];
};

export type PolicyListMeta = {
  mode?: string;
  persistence?: string | null;
  policy_count?: number;
  role_catalog?: string[];
};

export type PolicyListResponseOk = {
  ok: true;
  policies: PolicyAssignment[];
  meta?: PolicyListMeta;
  note?: string;
};

export type PolicyListResponseErr = {
  ok: false;
  policies: [];
  note?: string;
  code?: string;
  message?: string;
};

export type PolicyListResponse = PolicyListResponseOk | PolicyListResponseErr;

export type RoleDescriptor = {
  id: string;
  key: string;
  label: string;
  name?: string | null;
};

export type RolePolicyMeta = {
  assignable?: boolean;
  mode?: string;
  persistence?: string | null;
};

export type RolePolicySuccess = {
  ok: true;
  role: RoleDescriptor;
  policies: string[];
  meta?: RolePolicyMeta;
};

export type RolePolicyError = {
  ok: false;
  code?: string;
  message?: string;
  status?: number;
  missing_roles?: string[];
};

export type RolePolicyResult = RolePolicySuccess | RolePolicyError;

export type StubOnlyAccepted = {
  ok: true;
  note: "stub-only";
};

export type RolePolicyUpdateResult = RolePolicySuccess | RolePolicyError | StubOnlyAccepted;

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

function sanitizeStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  const seen = new Set<string>();
  const out: string[] = [];
  for (const item of value) {
    if (typeof item !== "string") continue;
    const trimmed = item.trim();
    if (trimmed === "" || seen.has(trimmed)) continue;
    seen.add(trimmed);
    out.push(trimmed);
  }
  out.sort((a, b) => a.localeCompare(b, undefined, { sensitivity: "base" }));
  return out;
}

function coercePolicyAssignment(value: unknown): PolicyAssignment | null {
  if (!isObject(value)) return null;
  const policyRaw = value.policy;
  if (typeof policyRaw !== "string") return null;
  const policy = policyRaw.trim();
  if (policy === "") return null;

  const labelRaw = value.label;
  const label =
    typeof labelRaw === "string" && labelRaw.trim() !== "" ? labelRaw.trim() : null;

  const descRaw = value.description;
  const description =
    typeof descRaw === "string" && descRaw.trim() !== "" ? descRaw.trim() : null;

  const roles = sanitizeStringArray(value.roles);

  return { policy, label, description, roles };
}

function coerceRoleDescriptor(value: unknown): RoleDescriptor | null {
  if (!isObject(value)) return null;

  const idRaw = value.id;
  const keyRaw = value.key;
  const labelRaw = value.label;

  if (typeof idRaw !== "string" || typeof keyRaw !== "string" || typeof labelRaw !== "string") {
    return null;
  }

  const id = idRaw.trim();
  const key = keyRaw.trim();
  const label = labelRaw.trim();

  if (id === "" || key === "" || label === "") {
    return null;
  }

  const nameRaw = value.name;
  const name =
    typeof nameRaw === "string" && nameRaw.trim() !== "" ? nameRaw.trim() : undefined;

  return { id, key, label, name };
}

function coerceRolePolicyResponse(value: unknown): RolePolicySuccess | null {
  if (!isObject(value)) return null;
  if (value.ok !== true) return null;

  const role = coerceRoleDescriptor(value.role);
  if (!role) return null;

  const policies = sanitizeStringArray(value.policies);

  let meta: RolePolicyMeta | undefined;
  if (isObject(value.meta)) {
    const metaRaw = value.meta as Record<string, unknown>;
    meta = {
      assignable: typeof metaRaw.assignable === "boolean" ? metaRaw.assignable : undefined,
      mode: typeof metaRaw.mode === "string" ? metaRaw.mode : undefined,
      persistence:
        metaRaw.persistence === null || typeof metaRaw.persistence === "string"
          ? (metaRaw.persistence as string | null)
          : undefined,
    };
  }

  return { ok: true, role, policies, meta };
}

export async function listRoles(): Promise<RoleListResponse> {
  try {
    const json = await apiGet<unknown>("/rbac/roles");
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
      const localRoles = LOCAL_ROLES_DEFAULT.map((name) => ({ id: name, name }));
      return { ok: true, roles: localRoles, note: "stub" };
    }

    const note = typeof j.note === "string" ? (j.note as string) : undefined;
    return { ok: true, roles: collected, note };
  } catch {
    return { ok: false, roles: [], note: "network_error" };
  }
}

export async function createRole(name: string): Promise<CreateRoleResult> {
  try {
    const res = await apiPost<unknown, { name: string }>("/rbac/roles", { name });
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

    const code = typeof j.code === "string" ? j.code : undefined;
    if (code === "RBAC_DISABLED" || note === "stub" || Object.keys(j).length === 0) {
      const normalized = sanitizeStringArray([name]);
      if (normalized.length === 0) {
        return { kind: "error", status: 400, code: "INVALID_ROLE" };
      }
      return { kind: "created", status: 201, roleId: normalized[0], roleName: normalized[0], raw: res };
    }
    if (code === "RBAC_DISABLED" || note === "stub" || Object.keys(j).length === 0) {
      const normalized = sanitizeStringArray([name]);
      if (normalized.length === 0) {
        return { kind: "error", status: 400, code: "INVALID_ROLE" };
      }
      return { kind: "created", status: 201, roleId: normalized[0], roleName: normalized[0], raw: res };
    }
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
      `/rbac/roles/${encodeURIComponent(identifier)}`,
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
    const res = await apiDelete<unknown>(`/rbac/roles/${encodeURIComponent(identifier)}`);
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

export async function listPolicyAssignments(): Promise<PolicyListResponse> {
  try {
    const json = await apiGet<unknown>("/rbac/policies");
    const root = isObject(json) ? (json as Record<string, unknown>) : {};
    const data = isObject(root.data) ? (root.data as Record<string, unknown>) : {};
    const policiesRaw = Array.isArray(data.policies) ? data.policies : [];
    const policies = policiesRaw
      .map(coercePolicyAssignment)
      .filter((item): item is PolicyAssignment => item !== null);

    const metaRaw = isObject(root.meta) ? (root.meta as Record<string, unknown>) : undefined;
    const meta: PolicyListMeta | undefined = metaRaw
      ? {
          mode: typeof metaRaw.mode === "string" ? metaRaw.mode : undefined,
          persistence:
            metaRaw.persistence === null || typeof metaRaw.persistence === "string"
              ? (metaRaw.persistence as string | null)
              : undefined,
          policy_count:
            typeof metaRaw.policy_count === "number"
              ? Number(metaRaw.policy_count)
              : undefined,
          role_catalog: sanitizeStringArray(metaRaw.role_catalog),
        }
      : undefined;

    const note = typeof root.note === "string" ? root.note : undefined;

    return { ok: true, policies, meta, note };
  } catch (err) {
    if (err instanceof HttpError) {
      const body = err.body;
      const code = isObject(body) && typeof body.code === "string" ? body.code : "REQUEST_FAILED";
      const message = isObject(body) && typeof body.message === "string" ? body.message : undefined;
      return { ok: false, policies: [], code, message };
    }
    return { ok: false, policies: [], code: "NETWORK_ERROR", message: "Network error" };
  }
}

export async function getRolePolicies(roleId: string): Promise<RolePolicyResult> {
  try {
    const json = await apiGet<unknown>(`/rbac/roles/${encodeURIComponent(roleId)}/policies`);
    const parsed = coerceRolePolicyResponse(json);
    if (parsed) return parsed;

    const root = isObject(json) ? (json as Record<string, unknown>) : {};
    if (root.ok === false) {
      return {
        ok: false,
        code: typeof root.code === "string" ? root.code : undefined,
        message: typeof root.message === "string" ? root.message : undefined,
        missing_roles: Array.isArray(root.missing_roles)
          ? sanitizeStringArray(root.missing_roles)
          : undefined,
      };
    }

    return { ok: false, code: "INVALID_RESPONSE", message: "Invalid response" };
  } catch (err) {
    if (err instanceof HttpError) {
      const body = err.body;
      const code = isObject(body) && typeof body.code === "string" ? body.code : undefined;
      const message = isObject(body) && typeof body.message === "string" ? body.message : undefined;
      const missing =
        isObject(body) && Array.isArray(body.missing_roles)
          ? sanitizeStringArray(body.missing_roles)
          : undefined;
      return { ok: false, status: err.status, code, message, missing_roles: missing };
    }
    return { ok: false, code: "NETWORK_ERROR", message: "Network error" };
  }
}

export async function updateRolePolicies(
  roleId: string,
  policies: string[]
): Promise<RolePolicyUpdateResult> {
  const uniquePolicies = sanitizeStringArray(policies);
  try {
    const json = await apiPut<unknown, { policies: string[] }>(
      `/rbac/roles/${encodeURIComponent(roleId)}/policies`,
      { policies: uniquePolicies }
    );

    if (isObject(json) && json.note === "stub-only") {
      return { ok: true, note: "stub-only" };
    }

    const parsed = coerceRolePolicyResponse(json);
    if (parsed) {
      return parsed;
    }

    const root = isObject(json) ? (json as Record<string, unknown>) : {};
    if (root.ok === false) {
      return {
        ok: false,
        code: typeof root.code === "string" ? root.code : undefined,
        message: typeof root.message === "string" ? root.message : undefined,
        missing_roles: Array.isArray(root.missing_roles)
          ? sanitizeStringArray(root.missing_roles)
          : undefined,
      };
    }

    return { ok: false, code: "INVALID_RESPONSE", message: "Invalid response" };
  } catch (err) {
    if (err instanceof HttpError) {
      const body = err.body;
      const code = isObject(body) && typeof body.code === "string" ? body.code : undefined;
      const message = isObject(body) && typeof body.message === "string" ? body.message : undefined;
      const missing =
        isObject(body) && Array.isArray(body.missing_roles)
          ? sanitizeStringArray(body.missing_roles)
          : undefined;
      return { ok: false, status: err.status, code, message, missing_roles: missing };
    }
    return { ok: false, code: "NETWORK_ERROR", message: "Network error" };
  }
}

export async function getUserRoles(userId: number): Promise<UserRolesResponse> {
  try {
    const json = await apiGet<unknown>(`/rbac/users/${encodeURIComponent(String(userId))}/roles`);
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
      `/rbac/users/${encodeURIComponent(String(userId))}/roles`,
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
      `/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleId)}`
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
      `/rbac/users/${encodeURIComponent(String(userId))}/roles/${encodeURIComponent(roleId)}`
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

    const json = await apiGet<unknown>("/rbac/users/search", { q, page: p, per_page: pp });
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
