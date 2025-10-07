import React from "react";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { vi } from "vitest";
import Users from "../Users";

const originalFetch = globalThis.fetch as typeof fetch;

function jsonResponse(status: number, body?: unknown) {
  return new Response(body !== undefined ? JSON.stringify(body) : null, {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function renderPage() {
  render(
    <MemoryRouter initialEntries={["/admin/users"]}>
      <Routes>
        <Route path="/admin/users" element={<Users />} />
      </Routes>
    </MemoryRouter>
  );
}

afterEach(() => {
  globalThis.fetch = originalFetch;
  vi.restoreAllMocks();
});

describe("Admin Users page", () => {
  test("allows editing an existing user", async () => {
    const user = userEvent.setup();
    let updateCalled = false;

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/api\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["Admin", "User"] });
      }

      if (method === "GET" && /\/api\/admin\/users\b/.test(url)) {
        if (!updateCalled) {
          return jsonResponse(200, {
            ok: true,
            data: [
              {
                id: 1,
                name: "Alice Admin",
                email: "alice@example.test",
                roles: ["Admin"],
              },
            ],
            meta: { page: 1, per_page: 25, total: 1, total_pages: 1 },
          });
        }
        return jsonResponse(200, {
          ok: true,
          data: [
            {
              id: 1,
              name: "Alice Updated",
              email: "alice@example.test",
              roles: ["Admin", "User"],
            },
          ],
          meta: { page: 1, per_page: 25, total: 1, total_pages: 1 },
        });
      }

      if (method === "PUT" && /\/api\/admin\/users\/1\b/.test(url)) {
        updateCalled = true;
        const body = JSON.parse(String(init?.body ?? "{}"));
        expect(body).toEqual({
          name: "Alice Updated",
          email: "alice@example.test",
          password: "new-secret",
          roles: ["admin", "user"],
        });
        return jsonResponse(200, {
          ok: true,
          user: {
            id: 1,
            name: "Alice Updated",
            email: "alice@example.test",
            roles: ["Admin", "User"],
          },
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    await screen.findByText("Alice Admin");

    const editButton = within(screen.getByRole("row", { name: /alice admin/i })).getByRole("button", { name: /edit/i });
    await user.click(editButton);

    const editCard = screen.getByText(/edit user/i).closest(".card") as HTMLElement | null;
    expect(editCard).not.toBeNull();
    const nameInput = await within(editCard!).findByLabelText(/name/i) as HTMLInputElement;
    const passwordInput = within(editCard!).getByLabelText(/reset password/i) as HTMLInputElement;
    const rolesSelect = within(editCard!).getByLabelText(/^Roles$/i) as HTMLSelectElement;

    await user.clear(nameInput);
    await user.type(nameInput, "Alice Updated");
    await user.type(passwordInput, "new-secret");
    await user.selectOptions(rolesSelect, ["admin", "user"]);

    const saveButton = within(editCard!).getByRole("button", { name: /^save$/i });
    await user.click(saveButton);

    await screen.findByText(/user updated\./i);

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: /alice updated/i })).toBeInTheDocument();
      expect(screen.getByRole("cell", { name: /admin, user/i })).toBeInTheDocument();
    });
  });

  test("creates and deletes a user with confirmation", async () => {
    const user = userEvent.setup();
    let created = false;
    let deleted = false;

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/api\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: ["Admin"] });
      }

      if (method === "GET" && /\/api\/admin\/users\b/.test(url)) {
        if (deleted) {
          return jsonResponse(200, {
            ok: true,
            data: [],
            meta: { page: 1, per_page: 25, total: 0, total_pages: 0 },
          });
        }
        if (created) {
          return jsonResponse(200, {
            ok: true,
            data: [
              {
                id: 2,
                name: "Bob User",
                email: "bob@example.test",
                roles: ["Admin"],
              },
            ],
            meta: { page: 1, per_page: 25, total: 1, total_pages: 1 },
          });
        }
        return jsonResponse(200, {
          ok: true,
          data: [
            {
              id: 1,
              name: "Alice Admin",
              email: "alice@example.test",
              roles: ["Admin"],
            },
          ],
          meta: { page: 1, per_page: 25, total: 1, total_pages: 1 },
        });
      }

      if (method === "POST" && /\/api\/admin\/users\b/.test(url)) {
        const body = JSON.parse(String(init?.body ?? "{}"));
        expect(body).toEqual({
          name: "Bob User",
          email: "bob@example.test",
          password: "secret123",
          roles: ["admin"],
        });
        created = true;
        return jsonResponse(200, {
          ok: true,
          user: {
            id: 2,
            name: "Bob User",
            email: "bob@example.test",
            roles: ["Admin"],
          },
        });
      }

      if (method === "DELETE" && /\/api\/admin\/users\/2\b/.test(url)) {
        deleted = true;
        return jsonResponse(200, { ok: true });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    await screen.findByText("Alice Admin");

    const createCard = screen.getByText(/create user/i).closest(".card") as HTMLElement | null;
    expect(createCard).not.toBeNull();

    const createNameInput = within(createCard!).getByLabelText(/name/i) as HTMLInputElement;
    const createEmailInput = within(createCard!).getByLabelText(/email/i) as HTMLInputElement;
    const createPasswordInput = within(createCard!).getByLabelText(/password/i) as HTMLInputElement;
    const createRolesSelect = within(createCard!).getByLabelText(/^Roles$/i) as HTMLSelectElement;

    await user.type(createNameInput, "Bob User");
    await user.type(createEmailInput, "bob@example.test");
    await user.type(createPasswordInput, "secret123");
    await user.selectOptions(createRolesSelect, ["admin"]);

    await user.click(within(createCard!).getByRole("button", { name: /^create$/i }));

    await screen.findByText(/user created\./i);
    await screen.findByText("Bob User");

    await waitFor(() => {
      expect((within(createCard!).getByLabelText(/name/i) as HTMLInputElement).value).toBe("");
      expect((within(createCard!).getByLabelText(/email/i) as HTMLInputElement).value).toBe("");
      expect((within(createCard!).getByLabelText(/password/i) as HTMLInputElement).value).toBe("");
      const select = within(createCard!).getByLabelText(/^Roles$/i) as HTMLSelectElement;
      expect(select.value).toBe("");
    });

    const deleteButton = within(screen.getByRole("row", { name: /bob user/i })).getByRole("button", { name: /delete/i });
    await user.click(deleteButton);

    const confirmTitle = await screen.findByText(/delete bob@example.test\?/i);
    const confirmAlert = confirmTitle.closest('[role="alert"]') as HTMLElement | null;
    expect(confirmAlert).not.toBeNull();

    await user.click(within(confirmAlert!).getByRole("button", { name: /^delete$/i }));

    await screen.findByText(/user deleted\./i);
    await screen.findByText(/no users/i);
  });
});
