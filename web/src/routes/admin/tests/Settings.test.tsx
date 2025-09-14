/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import React from "react";
import Settings from "../Settings";

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
    ...init,
  });
}

describe("Admin Settings page", () => {
  const originalFetch = global.fetch;
  let postBody: any = null;

  beforeEach(() => {
    postBody = null;
    global.fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);

      if (url === "/api/admin/settings" && (!init || init.method === undefined)) {
        return jsonResponse({
          ok: true,
          config: {
            core: {
              rbac: { enabled: true, roles: ["Admin", "Auditor", "Risk Manager", "User"], require_auth: false },
              audit: { enabled: true, retention_days: 365 },
              evidence: { enabled: true, max_mb: 25, allowed_mime: ["application/pdf", "image/png", "image/jpeg", "text/plain"] },
              avatars: { enabled: true, size_px: 128, format: "webp" },
            },
          },
        });
      }

      if (url === "/api/admin/settings" && init?.method === "POST") {
        try {
          postBody = JSON.parse(String(init.body ?? "{}"));
        } catch {
          postBody = null;
        }
        // Simulate stub-only behavior
        return jsonResponse({ ok: true, note: "stub-only" }, { status: 200 });
      }

      return jsonResponse({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    global.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("loads, submits, shows stub message, and sends contract-shaped body", async () => {
    render(<Settings />);

    // Wait for initial load to finish
    await waitFor(() => expect(screen.queryByText("Loading")).not.toBeInTheDocument());

    // Toggle Require Auth
    const requireAuth = screen.getByLabelText("Require Auth (Sanctum) for RBAC APIs") as HTMLInputElement;
    expect(requireAuth.checked).toBe(false);
    fireEvent.click(requireAuth);
    expect(requireAuth.checked).toBe(true);

    // Change retention days
    const retention = screen.getByLabelText("Retention days") as HTMLInputElement;
    fireEvent.change(retention, { target: { value: "180" } });
    expect(retention.value).toBe("180");

    // Submit
    fireEvent.click(screen.getByRole("button", { name: /save/i }));

    await screen.findByText("Validated. Not persisted (stub).");

    // Body contains only the contract keys under core
    expect(postBody).toBeTruthy();
    expect(postBody).toHaveProperty("core");

    const core = postBody.core;
    expect(core).toHaveProperty("rbac");
    expect(core).toHaveProperty("audit");
    expect(core).toHaveProperty("evidence");
    expect(core).toHaveProperty("avatars");

    // rbac.require_auth toggled true
    expect(core.rbac.require_auth).toBe(true);
    // audit.retention_days set to 180
    expect(core.audit.retention_days).toBe(180);
  });
});
