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
    rbac: { require_auth: boolean };
    audit: { retention_days: number };
    evidence: unknown;
    avatars: unknown;
  };
};

describe("Admin Settings page", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let postBody: unknown = null;

  beforeEach(() => {
    postBody = null;
    globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;

      if (url === "/api/admin/settings" && (!init || init.method === undefined)) {
        return jsonResponse({
          ok: true,
          config: {
            core: {
              rbac: { enabled: true, roles: ["Admin", "Auditor", "Risk Manager", "User"], require_auth: false },
              audit: { enabled: true, retention_days: 365 },
              evidence: {
                enabled: true,
                max_mb: 25,
                allowed_mime: ["application/pdf", "image/png", "image/jpeg", "text/plain"],
              },
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

    await waitFor(() => expect(screen.queryByText("Loading")).not.toBeInTheDocument());

    const requireAuth = screen.getByLabelText("Require Auth (Sanctum) for RBAC APIs") as HTMLInputElement;
    expect(requireAuth.checked).toBe(false);
    fireEvent.click(requireAuth);
    expect(requireAuth.checked).toBe(true);

    const retention = screen.getByLabelText("Retention days") as HTMLInputElement;
    fireEvent.change(retention, { target: { value: "180" } });
    expect(retention.value).toBe("180");

    fireEvent.click(screen.getByRole("button", { name: /save/i }));

    await screen.findByText("Validated. Not persisted (stub).");

    expect(postBody).toBeTruthy();
    expect(postBody).toHaveProperty("core");

    const core = (postBody as CoreBody).core;
    expect(core).toHaveProperty("rbac");
    expect(core).toHaveProperty("audit");
    expect(core).toHaveProperty("evidence");
    expect(core).toHaveProperty("avatars");

    expect(core.rbac.require_auth).toBe(true);
    expect(core.audit.retention_days).toBe(180);
  });
});
