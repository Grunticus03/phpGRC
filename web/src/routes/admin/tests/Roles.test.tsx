import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { vi, type Mock } from "vitest";

// Mock API layer used by Roles.tsx
vi.mock("../../../lib/api/rbac", () => ({
  listRoles: vi.fn(),
  createRole: vi.fn()
}));

import * as api from "../../../lib/api/rbac";
import Roles from "../Roles";

async function renderAt(path = "/admin/roles") {
  const ui = render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/roles" element={<Roles />} />
      </Routes>
    </MemoryRouter>
  );
  await waitFor(() => expect((api.listRoles as unknown as Mock).mock.calls.length).toBeGreaterThan(0));
  return ui;
}

afterEach(() => {
  vi.clearAllMocks();
});

describe("Admin Roles page", () => {
  test("renders list of roles", async () => {
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: ["Admin", "Auditor", "User"]
    });

    await renderAt();

    expect(await screen.findByRole("heading", { name: /rbac roles/i })).toBeInTheDocument();
    const list = screen.getByRole("list");
    for (const r of ["Admin", "Auditor", "User"]) {
      expect(within(list).getByText(r)).toBeInTheDocument();
    }
  });

  test("shows empty state when no roles", async () => {
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: []
    });

    await renderAt();

    await screen.findByRole("heading", { name: /rbac roles/i });
    expect(screen.getByText(/no roles defined/i)).toBeInTheDocument();
  });

  test("creates role successfully (201 created) and reloads list", async () => {
    const user = userEvent.setup();

    // Initial load: no roles
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({ ok: true, roles: [] });
    // After create: roles include new one
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: ["Compliance Lead"]
    });

    (api.createRole as unknown as Mock).mockResolvedValueOnce({
      kind: "created",
      status: 201,
      roleId: "role_compliance_lead",
      roleName: "Compliance Lead",
      raw: {}
    });

    await renderAt();

    await screen.findByRole("heading", { name: /rbac roles/i });

    const input = screen.getByLabelText(/create role/i);
    await user.clear(input);
    await user.type(input, "Compliance Lead");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(api.createRole).toHaveBeenCalledWith("Compliance Lead");

    // Message appears
    expect(await screen.findByText(/created role compliance lead/i)).toBeInTheDocument();

    // And list shows the role
    const list = screen.getByRole("list");
    expect(within(list).getByText("Compliance Lead")).toBeInTheDocument();
  });

  test("handles stub-only acceptance", async () => {
    const user = userEvent.setup();

    (api.listRoles as unknown as Mock).mockResolvedValueOnce({ ok: true, roles: [] });
    (api.createRole as unknown as Mock).mockResolvedValueOnce({
      kind: "stub",
      status: 202,
      acceptedName: "Temp",
      raw: {}
    });

    await renderAt();

    await screen.findByRole("heading", { name: /rbac roles/i });
    const input = screen.getByLabelText(/create role/i);
    await user.type(input, "Temp");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByText(/accepted: "Temp".*stub/i)).toBeInTheDocument();
  });

  test("handles 403 forbidden", async () => {
    const user = userEvent.setup();

    (api.listRoles as unknown as Mock).mockResolvedValueOnce({ ok: true, roles: ["Admin"] });
    (api.createRole as unknown as Mock).mockResolvedValueOnce({
      kind: "error",
      status: 403,
      code: "FORBIDDEN",
      raw: {}
    });

    await renderAt();

    await screen.findByText("Admin");
    const input = screen.getByLabelText(/create role/i);
    await user.type(input, "Test");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByText(/forbidden\. admin required\./i)).toBeInTheDocument();
  });

  test("handles 422 validation error", async () => {
    const user = userEvent.setup();

    (api.listRoles as unknown as Mock).mockResolvedValueOnce({ ok: true, roles: [] });
    (api.createRole as unknown as Mock).mockResolvedValueOnce({
      kind: "error",
      status: 422,
      code: "VALIDATION_FAILED",
      raw: {}
    });

    await renderAt();

    await screen.findByRole("heading", { name: /rbac roles/i });
    const input = screen.getByLabelText(/create role/i);
    await user.type(input, "X"); // too short
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByText(/validation error\. name must be 2–64 chars\./i)).toBeInTheDocument();
  });
});
