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
    const fetchMock = vi
      .fn(async (input: any, init?: any) => {
        const url = typeof input === "string" ? input : input.url;
        const method = (init?.method ?? "GET").toUpperCase();

        if (method === "GET" && /roles/i.test(url)) {
          return jsonResponse(200, [
            { name: "Compliance Lead", readOnly: true },
            { name: "Auditor", readOnly: false },
          ]);
        }

        if (method === "GET" && /users?/i.test(url)) {
          return jsonResponse(200, {
            id: "123",
            name: "Jane Admin",
            roles: ["Auditor"],
          });
        }

        if (method === "GET") return jsonResponse(200, {});
        return jsonResponse(204);
      }) as unknown as typeof fetch;

    (globalThis as any).fetch = fetchMock;

    renderPage();

    expect(
      await screen.findByRole("heading", { name: /user roles|rbac/i })
    ).toBeInTheDocument();
  });
});
