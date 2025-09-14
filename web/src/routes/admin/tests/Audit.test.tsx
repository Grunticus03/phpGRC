/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
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
    globalThis.fetch = vi.fn(async (...args: any[]) => {
      const url = String(args[0]);
      calls.push(url);

      if (url.includes("/api/audit/categories")) {
        return jsonResponse(["RBAC", "AUTH", "SYSTEM"]);
      }

      if (url.includes("/api/audit?")) {
        return jsonResponse({
          ok: true,
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

  it("loads categories and builds occurred_from/occurred_to query", async () => {
    render(<Audit />);

    // Wait until the <select> renders (initially an <input> before categories load)
    const catSelect = await screen.findByRole("combobox", { name: "Category" });
    expect(catSelect.tagName.toLowerCase()).toBe("select");
    fireEvent.change(catSelect, { target: { value: "RBAC" } });

    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2025-01-01" } });
    fireEvent.change(screen.getByLabelText("To"), { target: { value: "2025-01-02" } });
    fireEvent.change(screen.getByLabelText("Limit"), { target: { value: "25" } });

    fireEvent.click(screen.getByRole("button", { name: "Apply" }));

    await waitFor(() => {
      const hit = calls.find((u) => u.startsWith("/api/audit?"));
      expect(hit).toBeTruthy();
      expect(hit).toContain("category=RBAC");
      expect(hit).toContain("occurred_from=2025-01-01T00%3A00%3A00Z");
      expect(hit).toContain("occurred_to=2025-01-02T23%3A59%3A59Z");
      expect(hit).toContain("limit=25");
    });

    await screen.findByText("ULID001");
    await screen.findByText("rbac.user_role.attached");
  });

  it("falls back to text input if categories endpoint fails", async () => {
    (globalThis.fetch as any).mockImplementationOnce(async () => {
      throw new Error("boom");
    });
    (globalThis.fetch as any).mockImplementationOnce(async (...args: any[]) => {
      const url = String(args[0]);
      calls.push(url);
      return jsonResponse({ ok: true, items: [], nextCursor: null });
    });

    render(<Audit />);

    const catInput = await screen.findByLabelText("Category");
    expect(catInput.tagName.toLowerCase()).toBe("input");
  });
});
