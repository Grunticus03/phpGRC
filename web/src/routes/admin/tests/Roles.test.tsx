import React from "react";
import { render, screen } from "@testing-library/react";
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
  test("renders list of roles", async () => {
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, [{ name: "Compliance Lead", readOnly: true }]);
        }

        // Be generous for any other GETs the page might do (feature flags, etc.)
        if (method === "GET") return jsonResponse(200, {});

        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();

    expect(
      await screen.findByRole("heading", { name: /rbac roles/i })
    ).toBeInTheDocument();

    // Assert by visible text rather than list semantics to avoid brittleness.
    expect(await screen.findByText(/compliance lead/i)).toBeInTheDocument();
  });

  test("creates role successfully (201 created) and reloads list", async () => {
    let getCount = 0;
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          getCount += 1;
          // First GET shows empty list, second GET after create shows the role
          return getCount === 1
            ? jsonResponse(200, [])
            : jsonResponse(200, [{ name: "Compliance Lead", readOnly: true }]);
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

    // Wait for the form to be ready before interacting.
    const input = await screen.findByLabelText(/create role/i);
    await user.type(input, "Compliance Lead");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    // After reload, the created role is visible.
    expect(await screen.findByText(/compliance lead/i)).toBeInTheDocument();
  });

  test("handles stub-only acceptance", async () => {
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, []);
        }

        if (method === "POST" && /roles/i.test(url)) {
          return jsonResponse(202);
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

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(/Accepted:\s*"Temp".*Persistence not implemented\./i);

    // Informational hint remains on the page.
    expect(
      screen.getByText(/stub path accepted when rbac persistence is off/i)
    ).toBeInTheDocument();
  });

  test("handles 403 forbidden", async () => {
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

    expect(await screen.findByRole("alert")).toHaveTextContent(/forbidden/i);
  });

  test("handles 422 validation error", async () => {
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
    await user.type(input, "XX"); // >= 2 chars to enable submit
    await user.click(screen.getByRole("button", { name: /submit/i }));

    // Be tolerant of unicode dash differences by matching just the leading text.
    expect(await screen.findByRole("alert")).toHaveTextContent(/validation error/i);
  });
});
