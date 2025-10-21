/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { render, screen, fireEvent, waitFor, within } from "@testing-library/react";
import React from "react";
import CoreSettings from "../Settings";

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  const headers = new Headers(init.headers ?? {});
  if (!headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers,
  });
}

type Payload = {
  apply: true;
  rbac?: { require_auth?: boolean; user_search?: { default_per_page: number } };
  audit?: { retention_days: number };
  metrics?: { cache_ttl_seconds?: number; rbac_denies?: { window_days: number } };
  ui?: { time_format: string };
  evidence?: { blob_storage_path?: string; max_mb?: number };
};

describe("Core Settings page", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let postBody: unknown = null;
  let lastIfMatch: string | null = null;
  const blobHelperText = "Leave blank to keep storing evidence in the database.";

  beforeEach(() => {
    postBody = null;
    lastIfMatch = null;
    globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/admin/settings" && method === "GET") {
        return jsonResponse(
          {
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
                  blob_storage_path: "/opt/phpgrc/shared/blobs",
                },
                avatars: { enabled: true, size_px: 128, format: "webp" },
                ui: { time_format: "LOCAL" },
              },
            },
          },
          { headers: { ETag: 'W/"settings:test"' } }
        );
      }

      if (url === "/admin/settings" && method === "POST") {
        try {
          postBody = JSON.parse(String(init.body ?? "{}"));
        } catch {
          postBody = null;
        }
        const headers = new Headers(init.headers ?? {});
        lastIfMatch = headers.get("If-Match");

        return jsonResponse(
          { ok: true, note: "stub-only" },
          { status: 200, headers: { ETag: 'W/"settings:test-next"' } }
        );
      }

      return jsonResponse({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("loads, submits, shows stub message, and sends contract-shaped body", async () => {
    render(<CoreSettings />);

    await waitFor(() => expect(screen.queryByText("Loading")).toBeNull());
    expect(screen.getByText("0 disables caching. Max 30 days (2,592,000 seconds).")).toBeInTheDocument();
    expect(screen.getByText("Controls RBAC deny cache. Range: 7–365.")).toBeInTheDocument();

    const requireAuth = screen.getByLabelText("Enforce Authentication") as HTMLInputElement;
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

    const authWindow = screen.getByLabelText("Authentication window (days)") as HTMLInputElement;
    expect(authWindow.value).toBe("7");
    fireEvent.change(authWindow, { target: { value: "3" } });
    expect(authWindow.value).toBe("3");

    const timeFormatSelect = screen.getByLabelText(/Timestamp display/i) as HTMLSelectElement;
    fireEvent.change(timeFormatSelect, { target: { value: "ISO_8601" } });
    expect(timeFormatSelect.value).toBe("ISO_8601");

    const blobPath = screen.getByLabelText("Blob storage path") as HTMLInputElement;
    expect(blobPath.value).toBe("");
    expect(blobPath.placeholder).toBe("/opt/phpgrc/shared/blobs");
    expect(blobPath.classList.contains("placeholder-hide-on-focus")).toBe(true);
    expect(screen.getByText(blobHelperText)).toBeInTheDocument();
    fireEvent.focus(blobPath);
    expect(screen.getByText(blobHelperText)).toBeInTheDocument();
    expect(blobPath.placeholder).toBe("");
    fireEvent.change(blobPath, { target: { value: "/var/data/evidence" } });
    expect(blobPath.value).toBe("/var/data/evidence");
    fireEvent.blur(blobPath);
    expect(blobPath.placeholder).toBe("/opt/phpgrc/shared/blobs");
    expect(screen.getByText(blobHelperText)).toBeInTheDocument();

    const maxMbInput = screen.getByLabelText("Maximum file size (MB)") as HTMLInputElement;
    expect(maxMbInput.value).toBe("25");
    fireEvent.change(maxMbInput, { target: { value: "100" } });
    expect(maxMbInput.value).toBe("100");

    const adminForm = screen.getByRole("form", { name: "core-settings" });
    fireEvent.click(within(adminForm).getByRole("button", { name: /save/i }));

    await waitFor(() => {
      expect(lastIfMatch).toBe('W/"settings:test"');
    });
    expect(authWindow.value).toBe("7");

    expect(postBody).toBeTruthy();
    expect(postBody).toMatchObject({ apply: true });

    const payload = postBody as Payload;
    expect(payload.rbac?.require_auth).toBe(true);
    expect(payload.rbac?.user_search?.default_per_page).toBe(200);
    expect(payload.audit?.retention_days).toBe(180);
    expect(payload.ui?.time_format).toBe("ISO_8601");
    expect(payload.metrics?.rbac_denies).toBeUndefined();
    expect(payload.metrics?.cache_ttl_seconds).toBeUndefined();
    expect(payload.evidence?.blob_storage_path).toBe("/var/data/evidence");
    expect(payload.evidence?.max_mb).toBe(100);
  });

  it("clamps authentication window above max on save", async () => {
    render(<CoreSettings />);

    await waitFor(() => expect(screen.queryByText("Loading")).toBeNull());

    const authWindow = screen.getByLabelText("Authentication window (days)") as HTMLInputElement;
    expect(authWindow.value).toBe("7");

    fireEvent.change(authWindow, { target: { value: "400" } });
    expect(authWindow.value).toBe("400");

    const adminForm = screen.getByRole("form", { name: "core-settings" });
    fireEvent.click(within(adminForm).getByRole("button", { name: /save/i }));
    await waitFor(() => {
      expect(lastIfMatch).toBe('W/"settings:test"');
    });

    expect(postBody).toBeTruthy();
    const payload = postBody as Payload;
    expect(payload.metrics?.rbac_denies?.window_days).toBe(365);
    expect(authWindow.value).toBe("365");
  });
});
