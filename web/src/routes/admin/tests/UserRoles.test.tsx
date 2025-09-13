import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { vi, type Mock } from "vitest";

// Mock API layer used by UserRoles.tsx
vi.mock("../../../lib/api/rbac", () => ({
  listRoles: vi.fn(),
  getUserRoles: vi.fn(),
  replaceUserRoles: vi.fn(),
  attachUserRole: vi.fn(),
  detachUserRole: vi.fn()
}));

import * as api from "../../../lib/api/rbac";
import UserRoles from "../UserRoles";

function renderAt(path = "/admin/user-roles") {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/user-roles" element={<UserRoles />} />
      </Routes>
    </MemoryRouter>
  );
}

describe("Admin UserRoles page", () => {
  test("loads available roles and fetches a user by ID", async () => {
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: ["Admin", "Auditor", "User"]
    });

    (api.getUserRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      user: { id: 1, name: "Alice", email: "alice@example.com" },
      roles: ["User"]
    });

    const user = userEvent.setup();
    renderAt();

    const input = await screen.findByLabelText(/user id/i);
    await user.type(input, "1");
    await user.click(screen.getByRole("button", { name: /load/i }));

    expect(await screen.findByText(/alice@example.com/i)).toBeInTheDocument();
    expect(screen.getByText(/current roles/i)).toBeInTheDocument();
    expect(screen.getByText("User")).toBeInTheDocument();
  });

  test("attach a role not currently assigned", async () => {
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: ["Admin", "Auditor", "User"]
    });

    (api.getUserRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      user: { id: 1, name: "Alice", email: "alice@example.com" },
      roles: ["User"]
    });

    (api.attachUserRole as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      user: { id: 1, name: "Alice", email: "alice@example.com" },
      roles: ["User", "Auditor"]
    });

    const user = userEvent.setup();
    renderAt();

    const input = await screen.findByLabelText(/user id/i);
    await user.type(input, "1");
    await user.click(screen.getByRole("button", { name: /load/i }));

    const select = await screen.findByLabelText(/attach role/i);
    await user.selectOptions(select, "Auditor");
    await user.click(screen.getByRole("button", { name: /attach/i }));

    expect(api.attachUserRole).toHaveBeenCalledWith(1, "Auditor");
    expect(await screen.findByText("Auditor")).toBeInTheDocument();
  });

  test("detach a role", async () => {
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: ["Admin", "Auditor", "User"]
    });

    (api.getUserRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      user: { id: 2, name: "Bob", email: "bob@example.com" },
      roles: ["Admin", "User"]
    });

    (api.detachUserRole as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      user: { id: 2, name: "Bob", email: "bob@example.com" },
      roles: ["User"]
    });

    const user = userEvent.setup();
    renderAt();

    const input = await screen.findByLabelText(/user id/i);
    await user.type(input, "2");
    await user.click(screen.getByRole("button", { name: /load/i }));

    const list = await screen.findByRole("list");
    const adminItem = within(list).getByText("Admin").closest("li") as HTMLElement;
    const detachBtn = within(adminItem).getByRole("button", { name: /detach admin/i });
    await user.click(detachBtn);

    expect(api.detachUserRole).toHaveBeenCalledWith(2, "Admin");
    expect(await screen.findByText(/detached role/i)).toBeInTheDocument();
  });

  test("replace roles set", async () => {
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: ["Admin", "Auditor", "User"]
    });

    (api.getUserRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      user: { id: 3, name: "Cara", email: "cara@example.com" },
      roles: ["User"]
    });

    (api.replaceUserRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      user: { id: 3, name: "Cara", email: "cara@example.com" },
      roles: ["Admin"]
    });

    const user = userEvent.setup();
    renderAt();

    const input = await screen.findByLabelText(/user id/i);
    await user.type(input, "3");
    await user.click(screen.getByRole("button", { name: /load/i }));

    // Cast to HTMLInputElement for .checked
    const adminCheckbox = (await screen.findByLabelText("Admin")) as HTMLInputElement;
    const userCheckbox = screen.getByLabelText("User") as HTMLInputElement;
    const auditorCheckbox = screen.getByLabelText("Auditor") as HTMLInputElement;

    // Ensure starting state: "User" checked
    expect(userCheckbox.checked).toBe(true);

    // Toggle to Admin only
    if (!adminCheckbox.checked) await user.click(adminCheckbox);
    if (userCheckbox.checked) await user.click(userCheckbox);
    if (auditorCheckbox.checked) await user.click(auditorCheckbox);

    await user.click(screen.getByRole("button", { name: /replace/i }));

    expect(api.replaceUserRoles).toHaveBeenCalledWith(3, ["Admin"]);
    expect(await screen.findByText(/replaced roles\./i)).toBeInTheDocument();
  });

  test("handles forbidden on user lookup", async () => {
    (api.listRoles as unknown as Mock).mockResolvedValueOnce({
      ok: true,
      roles: ["Admin", "User"]
    });

    (api.getUserRoles as unknown as Mock).mockResolvedValueOnce({
      ok: false,
      code: "FORBIDDEN"
    });

    const user = userEvent.setup();
    renderAt();

    const input = await screen.findByLabelText(/user id/i);
    await user.type(input, "5");
    await user.click(screen.getByRole("button", { name: /load/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/forbidden/i);
  });
});
