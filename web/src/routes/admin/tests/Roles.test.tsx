import React from "react";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { vi } from "vitest";
import Roles from "../Roles";

const originalFetch = global.fetch;

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
  // restore fetch after each test
  global.fetch = originalFetch;
  vi.restoreAllMocks();
});

describe("Admin Roles page", () => {
  test("renders list of roles", async () => {
    // Initial GET returns one role so we can assert on visible UI
    const fetchMock = vi
      .fn((input: any, init?: any) => {
        if (!init || init.method === undefined || init.method === "GET") {
          return Promise.resolve(
            jsonResponse(200, [{ name: "Compliance Lead", readOnly: true }])
          );
        }
        return Promise.resolve(jsonResponse(404));
      }) as unknown as typeof fetch;
    (global as any).fetch = fetchMock;

    renderPage();

    expect(
      await screen.findByRole("heading", { name: /rbac roles/i })
    ).toBeInTheDocument();

    // It should show the role item with its label
    const list = await screen.findByRole("list");
    expect(within(list).getByText(/compliance lead/i)).toBeInTheDocument();
  });

  test("creates role successfully (201 created) and reloads list", async () => {
    // Sequence:
    // 1) GET -> []
    // 2) POST -> 201
    // 3) GET -> [{ name: "Compliance Lead" }]
    const fetchMock = vi
      .fn((input: any, init?: any) => {
        // count how many times we were called
        const call = (fetchMock as any).mock.calls.length;
        if (!init || init.method === undefined || init.method === "GET") {
          if (call === 0) {
            return Promise.resolve(jsonResponse(200, []));
          }
          return Promise.resolve(
            jsonResponse(200, [{ name: "Compliance Lead", readOnly: true }])
          );
        }
        if (init.method === "POST") {
          return Promise.resolve(jsonResponse(201, { id: 1, name: "Compliance Lead" }));
        }
        return Promise.resolve(jsonResponse(404));
      }) as unknown as typeof fetch;
    (global as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    await user.type(
      screen.getByLabelText(/create role/i),
      "Compliance Lead"
    );
    await user.click(screen.getByRole("button", { name: /submit/i }));

    // Assert on observable UI change (role appears in the list)
    expect(
      await screen.findByText(/compliance lead/i)
    ).toBeInTheDocument();
  });

  test("handles stub-only acceptance", async () => {
    // Sequence:
    // 1) GET -> []
    // 2) POST -> 202 Accepted
    // 3) GET -> [] (still none since persistence is off)
    const fetchMock = vi
      .fn((input: any, init?: any) => {
        const call = (fetchMock as any).mock.calls.length;
        if (!init || init.method === undefined || init.method === "GET") {
          return Promise.resolve(jsonResponse(200, []));
        }
        if (init.method === "POST") {
          return Promise.resolve(jsonResponse(202));
        }
        return Promise.resolve(jsonResponse(404));
      }) as unknown as typeof fetch;
    (global as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(/create role/i), "Temp");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    // Component shows a warning alert explaining it's a stub path
    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(
      /Accepted: "Temp"\. Persistence not implemented\./i
    );

    // And the static stub hint text remains present
    expect(
      screen.getByText(/stub path accepted when rbac persistence is off/i)
    ).toBeInTheDocument();
  });

  test("handles 403 forbidden", async () => {
    // Sequence:
    // 1) GET -> []
    // 2) POST -> 403 with JSON message
    const fetchMock = vi
      .fn((input: any, init?: any) => {
        if (!init || init.method === undefined || init.method === "GET") {
          return Promise.resolve(jsonResponse(200, []));
        }
        if (init.method === "POST") {
          return Promise.resolve(jsonResponse(403, { message: "Forbidden" }));
        }
        return Promise.resolve(jsonResponse(404));
      }) as unknown as typeof fetch;
    (global as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(/create role/i), "Auditor");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/forbidden/i);
  });

  test("handles 422 validation error", async () => {
    // Sequence:
    // 1) GET -> []
    // 2) POST -> 422 with JSON message
    const fetchMock = vi
      .fn((input: any, init?: any) => {
        if (!init || init.method === undefined || init.method === "GET") {
          return Promise.resolve(jsonResponse(200, []));
        }
        if (init.method === "POST") {
          return Promise.resolve(
            jsonResponse(422, {
              message: "Validation error. Name must be 2–64 chars.",
            })
          );
        }
        return Promise.resolve(jsonResponse(404));
      }) as unknown as typeof fetch;
    (global as any).fetch = fetchMock;

    renderPage();
    const user = userEvent.setup();

    // Use >= 2 chars so the button enables and the request is actually sent
    await user.type(screen.getByLabelText(/create role/i), "XX");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      /Validation error\. Name must be 2–64 chars\./i
    );
  });
});
