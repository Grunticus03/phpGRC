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

type CoreBody = {
  core: {
    rbac: { require_auth: boolean; user_search: { default_per_page: number } };
    audit: { retention_days: number };
    evidence: unknown;
    avatars: unknown;
    ui: { time_format: string };
  };
};

describe("Admin Settings page", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let putBody: unknown = null;

  beforeEach(() => {
    putBody = null;
    globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;

      // GET effective settings (DB-backed in app, but mocked here)
      if (url === "/api/admin/settings" && (!init || init.method === undefined)) {
        return jsonResponse({
          ok: true,
          config: {
            core: {
              rbac: {
                enabled: true,
                roles: ["Admin", "Auditor", "Risk Manager", "User"],
                require_auth: false,
                user_search: { default_per_page: 50 },
              },
              audit: { enabled: true, retention_days: 365 },
              evidence: {
                enabled: true,
                max_mb: 25,
                allowed_mime: ["application/pdf", "image/png", "image/jpeg", "text/plain"],
              },
              avatars: { enabled: true, size_px: 128, format: "webp" },
              ui: { time_format: "LOCAL" },
              // metrics come from DB in-app; not asserted here
            },
          },
        });
      }

      // PUT settings (create/update)
      if (url === "/api/admin/settings" && init?.method === "PUT") {
        try {
          putBody = JSON.parse(String(init.body ?? "{}"));
        } catch {
          putBody = null;
        }
        // Return stub-only note to trigger expected message
        return jsonResponse({ ok: true, note: "stub-only" }, { status: 200 });
      }

      return jsonResponse({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("loads, submits, shows stub message, and sends contract-shaped body", async () => {
    render(<Settings />);

    await waitFor(() => expect(screen.queryByText("Loading")).toBeNull());

    const requireAuth = screen.getByLabelText("Require Auth (Sanctum) for RBAC APIs") as HTMLInputElement;
    expect(requireAuth.checked).toBe(false);
    fireEvent.click(requireAuth);
    expect(requireAuth.checked).toBe(true);

    const perPage = screen.getByLabelText("User search default per-page") as HTMLInputElement;
    expect(perPage.value).toBe("50");
    fireEvent.change(perPage, { target: { value: "200" } });
    expect(perPage.value).toBe("200");

    const retention = screen.getByLabelText("Retention days") as HTMLInputElement;
    fireEvent.change(retention, { target: { value: "180" } });
    expect(retention.value).toBe("180");

    const timeFormatSelect = screen.getByLabelText(/Timestamp display/i) as HTMLSelectElement;
    fireEvent.change(timeFormatSelect, { target: { value: "ISO_8601" } });
    expect(timeFormatSelect.value).toBe("ISO_8601");

    fireEvent.click(screen.getByRole("button", { name: /save/i }));

    await screen.findByText("Validated. Not persisted (stub).");

    expect(putBody).toBeTruthy();
    expect(putBody).toHaveProperty("core");

    const core = (putBody as CoreBody).core;
    expect(core).toHaveProperty("rbac");
    expect(core.rbac.require_auth).toBe(true);
    expect(core.rbac.user_search.default_per_page).toBe(200);

    expect(core).toHaveProperty("audit");
    expect(core.audit.retention_days).toBe(180);
    expect(core).toHaveProperty("ui");
    expect(core.ui.time_format).toBe("ISO_8601");
  });
});

