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

async function parseJson(res: Response): Promise<any> {
  try {
    return await res.json();
  } catch {
    return null;
  }
}

export async function listRoles(): Promise<RoleListResponse> {
  const res = await fetch("/api/rbac/roles", { credentials: "same-origin" });
  const json = await parseJson(res);
  if (json && typeof json === "object" && Array.isArray(json.roles)) {
    return { ok: true, roles: json.roles, note: typeof json.note === "string" ? json.note : undefined };
  }
  return { ok: false as unknown as boolean, roles: [], note: "invalid_response" };
}

export async function createRole(name: string): Promise<CreateRoleResult> {
  const res = await fetch("/api/rbac/roles", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify({ name }),
  });
  const json = await parseJson(res);

  // Persist path (spec): 201 with role object
  if (res.status === 201 && json?.ok === true && json?.role?.id && json?.role?.name) {
    return {
      kind: "created",
      status: res.status,
      roleId: String(json.role.id),
      roleName: String(json.role.name),
      raw: json,
    };
  }

  // Stub path: either 202 or 200/400 with note: "stub-only"
  if ((res.status === 202 || res.status === 200 || res.status === 400) && json?.note === "stub-only") {
    const accepted = json?.accepted?.name ?? name;
    return { kind: "stub", status: res.status, acceptedName: String(accepted), raw: json };
  }

  // Validation or forbidden
  if (res.status === 422) {
    return { kind: "error", status: res.status, code: "VALIDATION_FAILED", raw: json };
  }
  if (res.status === 403) {
    return { kind: "error", status: res.status, code: "FORBIDDEN", raw: json };
  }

  return { kind: "error", status: res.status, code: json?.code, raw: json };
}
