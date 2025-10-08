/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi, type Mock } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import React from "react";
import Audit from "../Audit";

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
    ...init,
  });
}

describe("Admin Audit page", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let calls: string[] = [];

  beforeEach(() => {
    calls = [];
    globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      calls.push(url);

      if (url.includes("/api/audit/categories")) {
        return jsonResponse(["RBAC", "AUTH", "SYSTEM"]);
      }

      if (url.startsWith("/api/rbac/users/search")) {
        return jsonResponse({
          ok: true,
          data: [{ id: 7, name: "Alpha 01", email: "alpha01@example.test" }],
          meta: { page: 1, per_page: 10, total: 1, total_pages: 1 },
        });
      }

      if (url.startsWith("/api/audit?")) {
        return jsonResponse({
          ok: true,
          time_format: "ISO_8601",
          items: [
            {
              id: "ULID001",
              created_at: "2025-01-01T00:00:00Z",
              category: "RBAC",
              action: "rbac.user_role.attached",
              actor_id: 1,
              ip: "127.0.0.1",
              note: null,
            },
          ],
          nextCursor: null,
        });
      }

      return jsonResponse({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  function renderAudit(initialEntries?: string[]) {
    render(
      <MemoryRouter initialEntries={initialEntries}>
        <Audit />
      </MemoryRouter>
    );
  }

  it("loads categories and builds occurred_from/occurred_to query", async () => {
    renderAudit();

    const catSelect = await screen.findByRole("combobox", { name: "Category" });
    expect(catSelect.tagName.toLowerCase()).toBe("select");
    fireEvent.change(catSelect, { target: { value: "RBAC" } });

    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2025-01-01" } });
    fireEvent.change(screen.getByLabelText("To"), { target: { value: "2025-01-02" } });
    fireEvent.change(screen.getByLabelText("Limit"), { target: { value: "25" } });

    fireEvent.click(screen.getByRole("button", { name: "Apply" }));

    await waitFor(() => {
      const hits = calls.filter((u) => u.startsWith("/api/audit?"));
      expect(hits.length).toBeGreaterThan(0);
      const hit = hits[hits.length - 1];
      expect(hit).toContain("category=RBAC");
      expect(hit).toContain("occurred_from=2025-01-01T00%3A00%3A00Z");
      expect(hit).toContain("occurred_to=2025-01-02T23%3A59%3A59Z");
      expect(hit).toContain("limit=25");
    });

    await screen.findByText("ULID001");
    await screen.findByText(/Role attached by 1/i);
    await screen.findByLabelText(/Role attached \(rbac\.user_role\.attached\)/i);
  });

  it("lets you select an actor and includes actor_id in the query", async () => {
    renderAudit();

    await screen.findByLabelText("Category");

    fireEvent.change(screen.getByLabelText("Actor"), { target: { value: "alpha" } });
    fireEvent.click(screen.getByRole("button", { name: "Search" }));

    const selectBtns = await screen.findAllByRole("button", { name: "Select" });
    fireEvent.click(selectBtns[0]); // pick id 7

    fireEvent.click(screen.getByRole("button", { name: "Apply" }));

    await waitFor(() => {
      const hits = calls.filter((u) => u.startsWith("/api/audit?"));
      expect(hits.length).toBeGreaterThan(0);
      const hit = hits[hits.length - 1];
      expect(hit).toContain("actor_id=7");
    });
  });

  it("falls back to text input if categories endpoint fails", async () => {
    (globalThis.fetch as unknown as Mock).mockImplementationOnce(async () => {
      return jsonResponse({ ok: true, categories: [] });
    });
    (globalThis.fetch as unknown as Mock).mockImplementationOnce(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      calls.push(url);
      return jsonResponse({ ok: true, items: [], nextCursor: null });
    });

    renderAudit();

    const catInput = await screen.findByLabelText("Category");
    expect(catInput.tagName.toLowerCase()).toBe("input");
  });

  it("applies filters supplied in the query string", async () => {
    renderAudit(["/admin/audit?category=AUTH&occurred_from=2025-01-10&occurred_to=2025-01-10"]);

    await waitFor(() => {
      const hits = calls.filter((u) => u.startsWith("/api/audit?"));
      expect(hits.length).toBeGreaterThan(0);
      const hit = hits[hits.length - 1];
      expect(hit).toContain("category=AUTH");
      expect(hit).toContain("occurred_from=2025-01-10");
      expect(hit).toContain("occurred_to=2025-01-10");
    });
  });
});
