/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import React from "react";
import BrandingCard from "../branding/BrandingCard";
import { ToastProvider } from "../../../components/toast/ToastProvider";

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  const headers = new Headers(init.headers ?? {});
  if (!headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers,
  });
}

const SETTINGS_BODY = {
  ok: true,
  config: {
    ui: {
      brand: {
        title_text: "phpGRC — Dashboard",
        favicon_asset_id: null,
        primary_logo_asset_id: "as_primary",
        secondary_logo_asset_id: null,
        header_logo_asset_id: null,
        footer_logo_asset_id: null,
        footer_logo_disabled: false,
        assets: {
          filesystem_path: "/opt/phpgrc/shared/brands",
        },
      },
    },
  },
};

const PROFILES_BODY = {
  ok: true,
  profiles: [
    {
      id: "bp_default",
      name: "Default",
      is_default: true,
      is_active: false,
      is_locked: true,
      brand: {
        title_text: "phpGRC",
        favicon_asset_id: null,
        primary_logo_asset_id: null,
        secondary_logo_asset_id: null,
        header_logo_asset_id: null,
        footer_logo_asset_id: null,
        footer_logo_disabled: false,
        assets: {
          filesystem_path: "/opt/phpgrc/shared/brands",
        },
      },
      created_at: null,
      updated_at: null,
    },
    {
      id: "bp_custom",
      name: "Custom",
      is_default: false,
      is_active: true,
      is_locked: false,
      brand: SETTINGS_BODY.config.ui.brand,
      created_at: null,
      updated_at: null,
    },
  ],
};

const ASSETS_BODY = {
  ok: true,
  assets: [
    {
      id: "as_primary",
      profile_id: "bp_custom",
      kind: "primary_logo" as const,
      name: "primary.png",
      mime: "image/png",
      size_bytes: 1024,
      sha256: "abc",
      uploaded_by: "admin",
      created_at: "2025-09-30T12:00:00Z",
      url: "https://example.com/primary.png",
    },
  ],
};

describe("BrandingCard", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let fetchMock: ReturnType<typeof vi.fn>;
  let uploadBody: FormData | null = null;
  let saveBody: unknown = null;
  let lastIfMatch: string | null = null;

  beforeEach(() => {
    uploadBody = null;
    saveBody = null;
    lastIfMatch = null;

    fetchMock = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(SETTINGS_BODY, { headers: { ETag: 'W/"branding:1"' } });
      }

      if (url === "/api/settings/ui/brand-profiles" && method === "GET") {
        return jsonResponse(PROFILES_BODY);
      }

      if (url.startsWith("/api/settings/ui/brand-assets") && method === "GET") {
        return jsonResponse(ASSETS_BODY);
      }

      if (url === "/api/settings/ui" && method === "PUT") {
        saveBody = JSON.parse(String(init.body ?? "{}"));
        const headers = new Headers(init.headers ?? {});
        lastIfMatch = headers.get("If-Match");
        return jsonResponse(SETTINGS_BODY, { headers: { ETag: 'W/"branding:2"' } });
      }

      if (url === "/api/settings/ui/brand-assets" && method === "POST") {
        uploadBody = init.body as FormData;
        return jsonResponse({ ok: true, asset: ASSETS_BODY.assets[0] });
      }

      if (url.startsWith("/api/settings/ui/brand-assets/") && method === "DELETE") {
        return jsonResponse({ ok: true });
      }

      return jsonResponse({ ok: true });
    });

    globalThis.fetch = fetchMock as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("loads branding data and saves with If-Match", async () => {
    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );

    await waitFor(() => expect(screen.queryByText("Loading branding settings…")).toBeNull());
    expect(screen.getByLabelText("Branding profile")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "Restore default" }).length).toBeGreaterThan(0);

    fireEvent.change(screen.getByLabelText("Title text"), { target: { value: "New Title" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText('Branding saved for "Custom".');

    expect(lastIfMatch).toBe('W/"branding:1"');
    expect(saveBody).toMatchObject({
      ui: {
        brand: {
          title_text: "New Title",
          profile_id: "bp_custom",
          assets: { filesystem_path: "/opt/phpgrc/shared/brands" },
        },
      },
    });
  });

  it("includes updated asset path when saved", async () => {
    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );
    await waitFor(() => expect(screen.queryByText("Loading branding settings…")).toBeNull());

    const pathField = screen.getByLabelText("Brand assets directory") as HTMLInputElement;
    fireEvent.change(pathField, { target: { value: "/srv/custom-brands" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText('Branding saved for "Custom".');

    expect(saveBody).toMatchObject({
      ui: {
        brand: {
          assets: { filesystem_path: "/srv/custom-brands" },
          profile_id: "bp_custom",
        },
      },
    });
  });

  it("handles upload validations", async () => {
    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );
    await waitFor(() => expect(screen.queryByText("Loading branding settings…")).toBeNull());

    const fileInput = screen.getByLabelText("Upload Primary logo") as HTMLInputElement;

    const file = new File(["svg"], "logo.svg", { type: "image/svg+xml" });

    // trigger upload
    fireEvent.change(fileInput, { target: { files: [file] } });

    await screen.findByText("Upload successful.");
    expect(uploadBody).not.toBeNull();
    expect(uploadBody?.get("profile_id")).toBe("bp_custom");
  });

  it("handles 409 conflicts", async () => {
    let conflict = true;
    fetchMock.mockImplementation(async (...args) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(SETTINGS_BODY, { headers: { ETag: 'W/"branding:1"' } });
      }
      if (url === "/api/settings/ui/brand-profiles" && method === "GET") {
        return jsonResponse(PROFILES_BODY);
      }
      if (url.startsWith("/api/settings/ui/brand-assets") && method === "GET") {
        return jsonResponse(ASSETS_BODY);
      }
      if (url === "/api/settings/ui" && method === "PUT") {
        if (conflict) {
          conflict = false;
          return jsonResponse({ ok: false }, {
            status: 409,
            headers: { ETag: 'W/"branding:fresh"' },
          });
        }
        return jsonResponse(SETTINGS_BODY, { headers: { ETag: 'W/"branding:2"' } });
      }
      return jsonResponse({ ok: true });
    });

    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );
    await waitFor(() => expect(screen.queryByText("Loading branding settings…")).toBeNull());

    fireEvent.change(screen.getByLabelText("Title text"), { target: { value: "Conflict Title" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText("Branding changed elsewhere. Reloaded latest values.");
  });

  it("shows read-only state on 403", async () => {
    fetchMock.mockImplementation(async (...args) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse({}, { status: 403 });
      }
      if (url === "/api/settings/ui/brand-profiles" && method === "GET") {
        return jsonResponse({ ok: true, profiles: [] });
      }
      if (url.startsWith("/api/settings/ui/brand-assets") && method === "GET") {
        return jsonResponse({ ok: true, assets: [] });
      }
      return jsonResponse({ ok: true });
    });

    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );

    await screen.findByText("You do not have permission to update branding.");
    expect(screen.getByRole("button", { name: "Save" })).toBeDisabled();
  });
});
