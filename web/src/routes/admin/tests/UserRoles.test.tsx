import React from "react";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi } from "vitest";
import UserRoles from "../UserRoles";

const originalFetch = (globalThis as any).fetch;

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
  (globalThis as any).fetch = originalFetch;
  vi.restoreAllMocks();
});

describe("Admin UserRoles page", () => {
  test("renders and performs lookup then attach flow", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
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

    (globalThis as any).fetch = fetchMock;

    renderPage();

    // Page heading
    expect(await screen.findByRole("heading", { name: /user roles/i })).toBeInTheDocument();

    // Enter user id and load
    await user.type(screen.getByLabelText(/user id/i), "123");
    await user.click(screen.getByRole("button", { name: /load/i }));

    // User card appears
    const card = await screen.findByRole("heading", { name: /user/i });
    expect(card).toBeInTheDocument();

    // Pick Admin and attach
    await user.selectOptions(screen.getByLabelText(/attach role/i), "Admin");
    await user.click(screen.getByRole("button", { name: /attach/i }));

    // Assert Admin is present in current roles list
    await waitFor(() => {
      const list = screen.getByRole("list");
      expect(within(list).getByText("Admin")).toBeInTheDocument();
    });
  });
});
