import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi } from "vitest";
import Roles from "../Roles";

const originalFetch = globalThis.fetch as typeof fetch;

function jsonResponse(status: number, body?: unknown) {
  return new Response(body !== undefined ? JSON.stringify(body) : null, {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function renderPage() {
  render(
    <MemoryRouter initialEntries={["/admin/roles"]}>
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
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    expect(await screen.findByRole("heading", { name: /Roles Management/i })).toBeInTheDocument();
    expect(await screen.findByRole("table")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /Rename/i })).toHaveLength(2);
    expect(screen.getAllByRole("button", { name: /Delete/i })).toHaveLength(2);
  });

  test("submits create role (POST issued)", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(200, { ok: true, roles: [] });
      }
      if (method === "POST" && /\/rbac\/roles\b/.test(url)) {
        return jsonResponse(201, { ok: true, role: { id: "role_compliance", name: "compliance_lead" } });
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
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    const promptSpy = vi.spyOn(window, "prompt").mockReturnValue("Admin_Primary");

    renderPage();
    const renameBtn = await screen.findByRole("button", { name: /rename/i });
    await userEvent.click(renameBtn);

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringMatching(/\/rbac\/roles\/admin/),
        expect.objectContaining({ method: "PATCH" })
      );
    });

    promptSpy.mockRestore();
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
      return jsonResponse(200, { ok: true });
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);

    renderPage();
    const deleteBtn = await screen.findByRole("button", { name: /delete/i });
    await userEvent.click(deleteBtn);

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringMatching(/\/rbac\/roles\/admin/),
        expect.objectContaining({ method: "DELETE" })
      );
    });

    confirmSpy.mockRestore();
  });
});
