import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi } from "vitest";
import Roles from "../Roles";

const originalFetch = (globalThis as any).fetch;

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
  (globalThis as any).fetch = originalFetch;
  vi.restoreAllMocks();
});

describe("Admin Roles page", () => {
  test("renders the page with form controls", async () => {
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, []); // empty list is fine; we only smoke-check UI
        }

        if (method === "GET") return jsonResponse(200, {});
        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();

    expect(
      await screen.findByRole("heading", { name: /rbac roles/i })
    ).toBeInTheDocument();

    // Stable, non-brittle assertions (form semantics don’t change with list data).
    expect(await screen.findByLabelText(/create role/i)).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /submit/i })
    ).toBeInTheDocument();
  });

  test("submits create role (POST issued)", async () => {
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, []); // initial load
        }
        if (method === "POST" && /roles/i.test(url)) {
          return jsonResponse(201, { id: 1, name: "Compliance Lead" });
        }
        if (method === "GET") return jsonResponse(200, {});
        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Compliance Lead");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    // Assert behavior via side-effect (network) to avoid brittle UI timing/text.
    await waitFor(() => {
      const calledPost = (fetchMock as any).mock.calls.some(
        ([req, init]: [any, any]) => {
          const url = typeof req === "string" ? req : req?.url ?? "";
          const method = (init?.method ?? "GET").toUpperCase();
          return /roles/i.test(url) && method === "POST";
        }
      );
      expect(calledPost).toBe(true);
    });
  });

  test("handles stub-only acceptance (shows any alert)", async () => {
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, []);
        }
        if (method === "POST" && /roles/i.test(url)) {
          // 202 Accepted – component may render a generic alert; don't assert exact text.
          return jsonResponse(202, {});
        }
        if (method === "GET") return jsonResponse(200, {});
        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Temp");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  test("handles 403 forbidden (shows an alert)", async () => {
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, []);
        }
        if (method === "POST" && /roles/i.test(url)) {
          return jsonResponse(403, { message: "Forbidden" });
        }
        if (method === "GET") return jsonResponse(200, {});
        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Auditor");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  test("handles 422 validation error (shows an alert)", async () => {
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, []);
        }
        if (method === "POST" && /roles/i.test(url)) {
          return jsonResponse(422, {
            message: "Validation error. Name must be 2–64 chars.",
          });
        }
        if (method === "GET") return jsonResponse(200, {});
        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    const input = await screen.findByLabelText(/create role/i);
    // Use >= 2 chars so the button enables and the request is sent
    await user.type(input, "XX");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });
});
