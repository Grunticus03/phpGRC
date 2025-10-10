import React from "react";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi } from "vitest";
import UserRoles from "../UserRoles";

const ROUTER_FUTURE_FLAGS = { v7_startTransition: true, v7_relativeSplatPath: true } as const;

const originalFetch = globalThis.fetch as typeof fetch;

function jsonResponse(status: number, body?: unknown) {
  return new Response(body !== undefined ? JSON.stringify(body) : null, {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function renderPage() {
  render(
    <MemoryRouter future={ROUTER_FUTURE_FLAGS} initialEntries={["/admin/user-roles"]}>
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
        if (method === "GET" && /\/api\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: ["Admin", "Risk Manager", "User"] });
        }

        // Lookup user
        if (method === "GET" && /\/api\/rbac\/users\/123\/roles\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["User"],
          });
        }

        // Attach Admin
        if (method === "POST" && /\/api\/rbac\/users\/123\/roles\/risk_manager\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["Risk Manager", "User"],
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

    const attachSelect = await screen.findByLabelText(/attach role/i);
    expect(within(attachSelect).getByRole("option", { name: "Risk Manager" })).toBeInTheDocument();
    expect(within(attachSelect).queryByRole("option", { name: /select role/i })).toBeNull();

    await user.selectOptions(attachSelect, "risk_manager");
    await user.click(screen.getByRole("button", { name: /add/i }));

    await waitFor(() => {
      const list = screen.getByRole("list");
      expect(within(list).getByText("Risk Manager")).toBeInTheDocument();
    });

    expect(screen.getByText(/risk manager attached\./i)).toBeInTheDocument();
  });

  test("performs detach flow", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /\/api\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: ["admin", "auditor", "user"] });
        }

        if (method === "GET" && /\/api\/rbac\/users\/123\/roles\b/.test(url)) {
          return jsonResponse(200, {
            ok: true,
            user: { id: 123, name: "Jane Admin", email: "jane@example.com" },
            roles: ["Admin", "User"],
          });
        }

        if (method === "DELETE" && /\/api\/rbac\/users\/123\/roles\/admin\b/.test(url)) {
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
    const removeBtn = within(adminItem).getByRole("button", { name: /remove/i });
    await user.click(removeBtn);

    await waitFor(() => {
      const list = screen.getByRole("list");
      expect(within(list).queryByText("Admin")).toBeNull();
    });
    expect(screen.getByText(/admin detached\./i)).toBeInTheDocument();
  });

  test("searches users then selects one to load roles", async () => {
    const user = userEvent.setup();

    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const urlStr = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const url = new URL(urlStr, "http://localhost");
        const method = (init?.method ?? "GET").toUpperCase();

        // roles catalog
        if (method === "GET" && /\/api\/rbac\/roles\b/.test(urlStr)) {
          return jsonResponse(200, { ok: true, roles: ["admin", "auditor", "user"] });
        }

        // user search page 1
        if (method === "GET" && /\/api\/rbac\/users\/search\b/.test(urlStr) && (url.searchParams.get("page") ?? "1") === "1") {
          return jsonResponse(200, {
            data: [{ id: 123, name: "Jane Admin", email: "jane@example.com" }],
            meta: { page: 1, per_page: Number(url.searchParams.get("per_page") ?? "50"), total: 1, total_pages: 1 },
          });
        }

        // get roles after selecting
        if (method === "GET" && /\/api\/rbac\/users\/123\/roles\b/.test(urlStr)) {
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
