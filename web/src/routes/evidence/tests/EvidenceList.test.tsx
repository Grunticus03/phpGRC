/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import React from "react";
import EvidenceList from "../List";

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
    ...init,
  });
}

describe("Evidence List", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let calls: string[] = [];

  beforeEach(() => {
    calls = [];
    globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      calls.push(url);

      if (url.startsWith("/api/rbac/users/search")) {
        return jsonResponse({
          ok: true,
          data: [{ id: 42, name: "Alice Admin", email: "alice@example.test" }],
          meta: { page: 1, per_page: 10, total: 1, total_pages: 1 },
        });
      }

      if (url.startsWith("/api/evidence")) {
        return jsonResponse({
          ok: true,
          data: [
            {
              id: "ev_01X",
              owner_id: 42,
              filename: "report.pdf",
              mime: "application/pdf",
              size: 1234,
              sha256: "7F9C2BA4E88F827D616045507605853ED73B8063F4A9A6F5D5B1E5F0E9D5A1C3",
              version: 1,
              created_at: "2025-09-12T00:00:00Z",
            },
          ],
          next_cursor: null,
        });
      }

      return jsonResponse({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("selects owner via search and includes owner_id in evidence request", async () => {
    render(<EvidenceList />);

    // Initial render triggers an evidence load; then we drive owner selection and Apply.
    await screen.findByText("Evidence");

    const ownerInput = screen.getByLabelText("Owner") as HTMLInputElement;
    fireEvent.change(ownerInput, { target: { value: "alice" } });

    const searchBtn = screen.getByRole("button", { name: "Search" });
    fireEvent.click(searchBtn);

    const selectButtons = await screen.findAllByRole("button", { name: "Select" });
    fireEvent.click(selectButtons[0]); // select Alice id 42

    // Apply filters
    fireEvent.click(screen.getByRole("button", { name: "Apply" }));

    await waitFor(() => {
      const hits = calls.filter((u) => u.startsWith("/api/evidence?"));
      expect(hits.length).toBeGreaterThan(0);
      const last = hits[hits.length - 1];
      expect(String(last)).toContain("owner_id=42");
    });

    await screen.findByText("report.pdf");
  });
});

