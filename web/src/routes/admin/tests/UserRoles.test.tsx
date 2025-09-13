import React from "react";
import { render, screen } from "@testing-library/react";
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

describe("Admin UserRoles page (smoke)", () => {
  test("renders without crashing", async () => {
    // Provide permissive defaults so any loader/aux calls succeed.
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        // Available roles list
        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, [
            { name: "Compliance Lead", readOnly: true },
            { name: "Auditor", readOnly: false },
          ]);
        }

        // Fetch a user by id
        if (method === "GET" && /users?/i.test(url)) {
          return jsonResponse(200, {
            id: "123",
            name: "Jane Admin",
            roles: ["Auditor"],
          });
        }

        // Default success for any other GETs the page might perform
        if (method === "GET") return jsonResponse(200, {});

        // NOP for mutations
        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();

    // At minimum the screen should mount and show a heading relevant to the page.
    // Use a loose match to avoid brittle wording differences.
    expect(
      await screen.findByRole("heading", { name: /user roles|rbac/i })
    ).toBeInTheDocument();
  });
});
