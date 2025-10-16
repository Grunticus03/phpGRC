import React from "react";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi } from "vitest";
import Roles from "../Roles";

const ROUTER_FUTURE_FLAGS = { v7_startTransition: true, v7_relativeSplatPath: true } as const;

const originalFetch = globalThis.fetch as typeof fetch;

function jsonResponse(status: number, body?: unknown) {
  return new Response(body !== undefined ? JSON.stringify(body) : null, {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

const defaultPoliciesResponse = {
  ok: true,
  data: {
    policies: [
      {
        policy: "core.settings.manage",
        label: "Manage core settings",
        description: "Allows administrators to update global configuration values.",
        roles: ["admin"],
      },
      {
        policy: "core.audit.view",
        label: "View audit events",
        description: "Grants read-only access to the audit log.",
        roles: ["admin", "auditor"],
      },
      {
        policy: "ui.theme.view",
        label: "View theme settings",
        description: "Read-only access to theme configuration and branding assets.",
        roles: ["admin", "theme_manager", "theme_auditor"],
      },
      {
        policy: "ui.theme.manage",
        label: "Manage theme settings",
        description: "Allows editing theme configuration and branding assets.",
        roles: ["admin", "theme_manager"],
      },
      {
        policy: "ui.theme.pack.manage",
        label: "Manage theme packs",
        description: "Import, update, and delete theme pack archives.",
        roles: ["admin", "theme_manager"],
      },
    ],
  },
  meta: {
    mode: "persist",
    persistence: "true",
    policy_count: 5,
    role_catalog: ["admin", "auditor", "theme_manager", "theme_auditor"],
  },
};

function renderPage() {
  render(
    <MemoryRouter future={ROUTER_FUTURE_FLAGS} initialEntries={["/admin/roles"]}>
      <Routes>
        <Route path="/admin/roles" element={<Roles />} />
      </Routes>
    </MemoryRouter>
  );
}

afterEach(() => {
  globalThis.fetch = originalFetch;
  vi.restoreAllMocks();
});

describe("Admin Roles page", () => {
  test("renders list with rename/delete actions", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();
      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin", "auditor"] });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    expect(await screen.findByRole("heading", { name: /Roles Management/i })).toBeInTheDocument();
    expect(await screen.findByRole("table")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /Delete/i })).toHaveLength(2);
    expect(screen.getByRole("button", { name: "Rename Admin" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Rename Auditor" })).toBeInTheDocument();
  });

  test("submits create role and refreshes list", async () => {
    const roles: string[] = [];

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles });
      }
      if (method === "POST" && /\/rbac\/roles\b/.test(url)) {
        roles.push("compliance_lead");
        return jsonResponse(201, { ok: true, role: { id: "role_compliance", name: "compliance_lead" } });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Compliance");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ method: "POST" }));
    });

    expect(await screen.findByText("Compliance Lead")).toBeInTheDocument();
  });

  test("renders numeric role names from API payload", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();
      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin", 123] });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    expect(await screen.findByText("123")).toBeInTheDocument();
  });

  test("shows friendly validation message on duplicate name", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin"] });
      }
      if (method === "POST" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(422, {
          ok: false,
          code: "VALIDATION_FAILED",
          message: "The given data was invalid.",
          errors: { name: ['Role "Admin" already exists.'] },
        });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.clear(input);
    await user.type(input, "Admin");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent('Role "Admin" already exists.');
  });

  test("rename issues PATCH request", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin"] });
      }
      if (method === "PATCH" && /\/rbac\/roles\//.test(url)) {
        return jsonResponse(200, { ok: true, role: { id: "role_admin", name: "admin_primary" } });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    const user = userEvent.setup();
    renderPage();
    const renameBtn = await screen.findByRole("button", { name: "Rename Admin" });
    await user.click(renameBtn);

    const input = await screen.findByLabelText("Role name");
    await user.clear(input);
    await user.type(input, "Admin Primary");

    await user.click(screen.getByRole("button", { name: /^Rename$/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringMatching(/\/rbac\/roles\/admin/),
        expect.objectContaining({ method: "PATCH" })
      );
    });
  });

  test("delete issues DELETE request", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin"] });
      }
      if (method === "DELETE" && /\/rbac\/roles\//.test(url)) {
        return jsonResponse(200, { ok: true });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    const user = userEvent.setup();
    renderPage();
    const deleteBtn = await screen.findByRole("button", { name: /^Delete$/i });
    await user.click(deleteBtn);

    const dialog = await screen.findByRole("dialog", { name: "Delete Admin?" });
    const confirmBtn = within(dialog).getByRole("button", { name: /^Delete$/i });
    await user.click(confirmBtn);

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringMatching(/\/rbac\/roles\/admin/),
        expect.objectContaining({ method: "DELETE" })
      );
    });
  });

  test("allows managing permissions and saving changes", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin"] });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      if (method === "GET" && /\/rbac\/roles\/admin\/policies\b/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          role: { id: "role_admin", key: "admin", label: "Admin", name: "Admin" },
          policies: ["core.settings.manage", "ui.theme.manage", "ui.theme.pack.manage", "ui.theme.view"],
          meta: { assignable: true, mode: "persist" },
        });
      }
      if (method === "PUT" && /\/rbac\/roles\/admin\/policies\b/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          role: { id: "role_admin", key: "admin", label: "Admin", name: "Admin" },
          policies: ["core.audit.view", "ui.theme.manage", "ui.theme.pack.manage", "ui.theme.view"],
          meta: { assignable: true, mode: "persist" },
        });
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    const user = userEvent.setup();

    renderPage();

    const manageBtn = await screen.findByRole("button", { name: /Manage permissions/i });
    await user.click(manageBtn);

    const settingsCheckbox = (await screen.findByLabelText(
      /Manage core settings/i
    )) as HTMLInputElement;
    if (settingsCheckbox.checked) {
      await user.click(settingsCheckbox); // remove to ensure change
    }

    const auditCheckbox = (await screen.findByLabelText(
      /View audit events/i
    )) as HTMLInputElement;
    if (!auditCheckbox.checked) {
      await user.click(auditCheckbox);
    }

    const saveBtn = screen.getByRole("button", { name: /Save changes/i });
    await waitFor(() => expect(saveBtn).toBeEnabled());
    await user.click(saveBtn);

    await waitFor(() => {
      const mockedFetch = fetchMock as unknown as {
        mock: { calls: Array<[RequestInfo | URL, RequestInit | undefined]> };
      };
      let found = false;
      for (const [request, options] of mockedFetch.mock.calls) {
        if (
          typeof request === "string" &&
          /\/rbac\/roles\/admin\/policies\b/.test(request) &&
          (options?.method ?? "GET").toUpperCase() === "PUT"
        ) {
          const body = JSON.parse((options?.body as string) ?? "{}") as { policies?: unknown };
          expect(body.policies).toEqual([
            "core.audit.view",
            "ui.theme.manage",
            "ui.theme.pack.manage",
            "ui.theme.view",
          ]);
          found = true;
          break;
        }
      }
      expect(found).toBe(true);
    });

    expect(await screen.findByText(/Updated permissions for Admin/i)).toBeInTheDocument();
  });

  test("groups theme policies under a Theme heading", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin"] });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      if (method === "GET" && /\/rbac\/roles\/admin\/policies\b/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          role: { id: "role_admin", key: "admin", label: "Admin", name: "Admin" },
          policies: ["core.settings.manage", "ui.theme.manage", "ui.theme.pack.manage", "ui.theme.view"],
          meta: { assignable: true, mode: "persist" },
        });
      }

      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    const user = userEvent.setup();

    renderPage();

    const manageBtn = await screen.findByRole("button", { name: /Manage permissions/i });
    await user.click(manageBtn);

    const themeHeading = await screen.findByRole("heading", { name: /^Theme$/i });
    expect(themeHeading).toBeInTheDocument();
    const themeSection = themeHeading.parentElement as HTMLElement | null;
    expect(themeSection).not.toBeNull();
    expect(within(themeSection as HTMLElement).getByLabelText(/View theme settings/i)).toBeInTheDocument();
    expect(within(themeSection as HTMLElement).getByLabelText(/Manage theme packs/i)).toBeInTheDocument();

    expect(await screen.findByRole("heading", { name: /^General$/i })).toBeInTheDocument();
  });

  test("shows stub notice when policy update is accepted without persistence", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["admin"] });
      }
      if (method === "GET" && /\/rbac\/policies\b/.test(url)) {
        return jsonResponse(200, defaultPoliciesResponse);
      }
      if (method === "GET" && /\/rbac\/roles\/admin\/policies\b/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          role: { id: "role_admin", key: "admin", label: "Admin", name: "Admin" },
          policies: ["ui.theme.view"],
          meta: { assignable: true, mode: "stub" },
        });
      }
      if (method === "PUT" && /\/rbac\/roles\/admin\/policies\b/.test(url)) {
        return jsonResponse(202, { ok: true, note: "stub-only" });
      }
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    const user = userEvent.setup();

    renderPage();

    const manageBtn = await screen.findByRole("button", { name: /Manage permissions/i });
    await user.click(manageBtn);

    const settingsCheckbox = (await screen.findByLabelText(
      /Manage core settings/i
    )) as HTMLInputElement;
    await user.click(settingsCheckbox);

    const saveBtn = screen.getByRole("button", { name: /Save changes/i });
    await waitFor(() => expect(saveBtn).toBeEnabled());
    await user.click(saveBtn);

    expect(await screen.findByText(/Stub mode: permissions accepted but not persisted/i)).toBeInTheDocument();
  });
});
