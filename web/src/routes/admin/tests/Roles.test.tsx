import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi, type Mock } from "vitest";
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
  test("renders the page with form controls", async () => {
    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: [] });
        }
        if (method === "GET") return jsonResponse(200, { ok: true });

        return jsonResponse(204);
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();

    expect(await screen.findByRole("heading", { name: /rbac roles/i })).toBeInTheDocument();
    expect(await screen.findByLabelText(/create role/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /submit/i })).toBeInTheDocument();
  });

  test("submits create role (POST issued)", async () => {
    const f = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      const method = (init?.method ?? "GET").toUpperCase();

      if (method === "GET" && /roles/i.test(url)) return jsonResponse(200, []); // initial load
      if (method === "POST" && /roles/i.test(url))
        return jsonResponse(201, { ok: true, role: { id: "role_compliance_lead", name: "Compliance Lead" } });
      if (method === "GET") return jsonResponse(200, {});
      return jsonResponse(204);
    });
    globalThis.fetch = f as unknown as typeof fetch;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Compliance Lead");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    await waitFor(() => {
      const calls = (f as unknown as Mock).mock.calls as Array<Parameters<typeof fetch>>;
      const calledPost = calls.some(([req, init]) => {
        const url = typeof req === "string" ? req : (req as Request).url ?? String(req);
        const method = (init?.method ?? "GET").toUpperCase();
        return /roles/i.test(url) && method === "POST";
      });
      expect(calledPost).toBe(true);
    });
  });

  test("handles stub-only acceptance (shows any alert)", async () => {
    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: [] });
        }
        if (method === "POST" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(202, { ok: false, note: "stub-only", accepted: { name: "Temp" } });
        }
        if (method === "GET") return jsonResponse(200, { ok: true });

        return jsonResponse(204);
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Temp");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  test("handles 403 forbidden (shows an alert)", async () => {
    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: [] });
        }
        if (method === "POST" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(403, { ok: false, code: "FORBIDDEN" });
        }
        if (method === "GET") return jsonResponse(200, { ok: true });

        return jsonResponse(204);
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Auditor");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  test("handles 422 validation error (shows an alert)", async () => {
    const fetchMock = vi
      .fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(200, { ok: true, roles: [] });
        }
        if (method === "POST" && /\/rbac\/roles\b/.test(url)) {
          return jsonResponse(422, { ok: false, code: "VALIDATION_FAILED" });
        }
        if (method === "GET") return jsonResponse(200, { ok: true });

        return jsonResponse(204);
      }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "XX");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });
});
