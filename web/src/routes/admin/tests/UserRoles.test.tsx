import React from "react";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi } from "vitest";
import UserRoles from "../UserRoles";

const originalFetch = globalThis.fetch as typeof fetch;

function jsonResponse(status: number, body?: unknown) {
  return new Response(body !== undefined ? JSON.stringify(body) : null, {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function renderPage() {
  render(
    <MemoryRouter initialEntries={["/admin/user-roles"]}>
      <Routes>
        <Route path="/admin/user-roles" element={<UserRoles />} />
      </Routes>
    </MemoryRouter>
  );
}

afterEach(() => {
  globalThis.fetch = originalFetch;
  vi.restoreAllMocks();
});

describe("Admin UserRoles page", () => {
  test("renders and performs lookup then attach flow", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        // List roles
        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: ["Admin", "Auditor", "User"] });
        }

        // Lookup user
        if (method === "GET" && /\/rbac\/users\/123\/roles\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["User"],
          });
        }

        // Attach Admin
        if (method === "POST" && /\/rbac\/users\/123\/roles\/Admin\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["Admin", "User"],
          });
        }

        return jsonResponse(200, { ok: true });
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    expect(await screen.findByRole("heading", { name: /user roles/i })).toBeInTheDocument();

    await user.type(screen.getByLabelText(/user id/i), "123");
    await user.click(screen.getByRole("button", { name: /load/i }));

    await screen.findByRole("heading", { level: 2, name: /^User$/ });

    await user.selectOptions(screen.getByLabelText(/attach role/i), "Admin");
    await user.click(screen.getByRole("button", { name: /attach/i }));

    await waitFor(() => {
      const list = screen.getByRole("list");
      expect(within(list).getByText("Admin")).toBeInTheDocument();
    });
  });

  test("performs detach flow", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: ["Admin", "Auditor", "User"] });
        }

        if (method === "GET" && /\/rbac\/users\/123\/roles\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["Admin", "User"],
          });
        }

        if (method === "DELETE" && /\/rbac\/users\/123\/roles\/Admin\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["User"],
          });
        }

        return jsonResponse(200, { ok: true });
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    await user.type(screen.getByLabelText(/user id/i), "123");
    await user.click(screen.getByRole("button", { name: /load/i }));

    await waitFor(() => {
      const list = screen.getByRole("list");
      expect(within(list).getByText("Admin")).toBeInTheDocument();
    });

    const adminItem = within(screen.getByRole("list")).getByText("Admin").closest("li") as HTMLElement;
    const detachBtn = within(adminItem).getByRole("button", { name: /detach/i });
    await user.click(detachBtn);

    await waitFor(() => {
      const list = screen.getByRole("list");
      expect(within(list).queryByText("Admin")).toBeNull();
    });
  });

  test("replaces roles successfully", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        // catalog
        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: ["Admin", "Auditor", "User"] });
        }
        // lookup with initial role User
        if (method === "GET" && /\/rbac\/users\/123\/roles\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["User"],
          });
        }
        // replace to Admin only
        if (method === "PUT" && /\/rbac\/users\/123\/roles\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["Admin"],
          });
        }
        return jsonResponse(200, { ok: true });
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    await user.type(screen.getByLabelText(/user id/i), "123");
    await user.click(screen.getByRole("button", { name: /load/i }));
    await screen.findByRole("heading", { level: 2, name: /^User$/ });

    const multi = screen.getByLabelText(/replace roles/i) as HTMLSelectElement;

    // Deselect "User", select "Admin"
    await user.deselectOptions(multi, "User");
    await user.selectOptions(multi, "Admin");

    await user.click(screen.getByRole("button", { name: /^replace$/i }));

    await waitFor(() => {
      const list = screen.getByRole("list");
      expect(within(list).getByText("Admin")).toBeInTheDocument();
      expect(within(list).queryByText("User")).toBeNull();
      expect(screen.getByText(/roles replaced\./i)).toBeInTheDocument();
    });
  });

  test("replace surfaces ROLE_NOT_FOUND with message", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          // include "Manager" so UI can select it
          return jsonResponse(200, { ok: true, roles: ["Admin", "Manager", "User"] });
        }
        if (method === "GET" && /\/rbac\/users\/123\/roles\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["User"],
          });
        }
        if (method === "PUT" && /\/rbac\/users\/123\/roles\b/.test(url)) {
          // API says unknown role; include message listing missing roles
          return jsonResponse(422, {
            ok: false,
            code: "ROLE_NOT_FOUND",
            message: "Unknown roles: Manager",
            missing_roles: ["Manager"],
          });
        }
        return jsonResponse(200, { ok: true });
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    await user.type(screen.getByLabelText(/user id/i), "123");
    await user.click(screen.getByRole("button", { name: /load/i }));
    await screen.findByRole("heading", { level: 2, name: /^User$/ });

    const multi = screen.getByLabelText(/replace roles/i) as HTMLSelectElement;
    await user.deselectOptions(multi, "User");
    await user.selectOptions(multi, "Manager"); // not actually valid on server

    await user.click(screen.getByRole("button", { name: /^replace$/i }));

    await waitFor(() => {
      const alert = screen.getByRole("status");
      expect(within(alert).getByText(/replace failed: role_not_found/i)).toBeInTheDocument();
      expect(within(alert).getByText(/unknown roles: manager/i)).toBeInTheDocument();
    });
  });

  test("searches users then selects one to load roles", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const urlStr = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const url = new URL(urlStr, "http://localhost");
        const method = (init?.method ?? "GET").toUpperCase();

        // roles catalog
        if (method === "GET" && /\/rbac\/roles\b/.test(urlStr)) {
          return jsonResponse(200, { ok: true, roles: ["Admin", "Auditor", "User"] });
        }

        // user search page 1
        if (method === "GET" && /\/rbac\/users\/search\b/.test(urlStr) && (url.searchParams.get("page") ?? "1") === "1") {
          return jsonResponse(200, {
            data: [{ id: 123, name: "Jane Admin", email: "jane@example.com" }],
            meta: { page: 1, per_page: Number(url.searchParams.get("per_page") ?? "50"), total: 1, total_pages: 1 },
          });
        }

        // get roles after selecting
        if (method === "GET" && /\/rbac\/users\/123\/roles\b/.test(urlStr)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["User"],
          });
        }

        return jsonResponse(404, { code: "NOT_FOUND" });
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    // perform search
    await user.type(screen.getByLabelText(/query/i), "jane");
    await user.click(screen.getByRole("button", { name: /search/i }));

    // see results table and select
    await waitFor(() => {
      expect(screen.getByRole("table")).toBeInTheDocument();
      expect(screen.getByText("jane@example.com")).toBeInTheDocument();
    });

    const row = screen.getByText("jane@example.com").closest("tr") as HTMLElement;
    await user.click(within(row).getByRole("button", { name: /select/i }));

    // user card appears
    await screen.findByRole("heading", { level: 2, name: /^User$/ });
    expect(screen.getByText(/jane admin/i)).toBeInTheDocument();
  });
});
