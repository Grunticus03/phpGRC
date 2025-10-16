/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import React from "react";

vi.mock("../../../lib/themeAccess", async () => {
  const actual = await vi.importActual<typeof import("../../../lib/themeAccess")>(
    "../../../lib/themeAccess"
  );
  return {
    ...actual,
    getThemeAccess: vi
      .fn()
      .mockResolvedValue({
        canView: true,
        canManage: true,
        canManagePacks: true,
        roles: ["admin"],
      }),
  };
});

import BrandingCard from "../branding/BrandingCard";
import { ToastProvider } from "../../../components/toast/ToastProvider";
import { getThemeAccess } from "../../../lib/themeAccess";

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
        title_text: "phpGRC",
        favicon_asset_id: null,
        primary_logo_asset_id: "as_primary",
        secondary_logo_asset_id: "as_secondary",
        header_logo_asset_id: "as_header",
        footer_logo_asset_id: "as_footer",
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
      name: "contoso--grp123.webp",
      display_name: "contoso.webp",
      mime: "image/webp",
      size_bytes: 1024,
      sha256: "abc",
      uploaded_by: "admin",
      created_at: "2025-09-30T12:00:00Z",
      url: "https://example.com/contoso--grp123.webp",
    },
    {
      id: "as_secondary",
      profile_id: "bp_custom",
      kind: "secondary_logo" as const,
      name: "contoso--grp123--secondary-logo.webp",
      display_name: "contoso.webp",
      mime: "image/webp",
      size_bytes: 900,
      sha256: "def",
      uploaded_by: "admin",
      created_at: "2025-09-30T12:00:01Z",
      url: "https://example.com/contoso--grp123--secondary-logo.webp",
    },
    {
      id: "as_header",
      profile_id: "bp_custom",
      kind: "header_logo" as const,
      name: "contoso--grp123--header-logo.webp",
      display_name: "contoso.webp",
      mime: "image/webp",
      size_bytes: 880,
      sha256: "ghi",
      uploaded_by: "admin",
      created_at: "2025-09-30T12:00:02Z",
      url: "https://example.com/contoso--grp123--header-logo.webp",
    },
    {
      id: "as_footer",
      profile_id: "bp_custom",
      kind: "footer_logo" as const,
      name: "contoso--grp123--footer-logo.webp",
      display_name: "contoso.webp",
      mime: "image/webp",
      size_bytes: 860,
      sha256: "jkl",
      uploaded_by: "admin",
      created_at: "2025-09-30T12:00:03Z",
      url: "https://example.com/contoso--grp123--footer-logo.webp",
    },
    {
      id: "as_favicon",
      profile_id: "bp_custom",
      kind: "favicon" as const,
      name: "contoso--grp123--favicon.webp",
      display_name: "contoso.webp",
      mime: "image/webp",
      size_bytes: 400,
      sha256: "mno",
      uploaded_by: "admin",
      created_at: "2025-09-30T12:00:04Z",
      url: "https://example.com/contoso--grp123--favicon.webp",
    },
  ],
};

const VARIANT_MAP = ASSETS_BODY.assets.reduce<Record<string, (typeof ASSETS_BODY.assets)[number]>>((acc, asset) => {
  acc[asset.kind] = asset;
  return acc;
}, {});

describe("BrandingCard", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let fetchMock: ReturnType<typeof vi.fn>;
  let uploadBody: FormData | null = null;
  let saveBody: unknown = null;
  let lastIfMatch: string | null = null;
  let createdProfileName: string | null = null;
  let profileCounter = 0;
  let profileList: typeof PROFILES_BODY.profiles = [];

  beforeEach(() => {
    uploadBody = null;
    saveBody = null;
    lastIfMatch = null;
    createdProfileName = null;
    profileList = PROFILES_BODY.profiles.map((profile) => ({ ...profile }));
    profileCounter = profileList.length;
    vi.mocked(getThemeAccess).mockResolvedValue({
      canView: true,
      canManage: true,
      canManagePacks: true,
      roles: ["admin"],
    });

    fetchMock = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(SETTINGS_BODY, { headers: { ETag: 'W/"branding:1"' } });
      }

      if (url === "/api/settings/ui/brand-profiles" && method === "GET") {
        return jsonResponse({ ok: true, profiles: profileList });
      }

      if (url === "/api/settings/ui/brand-profiles" && method === "POST") {
        const parsed = JSON.parse(String(init.body ?? "{}")) as { name?: string };
        const name = typeof parsed.name === "string" ? parsed.name : "";
        createdProfileName = name;
        const newProfile = {
          id: `bp_created_${++profileCounter}`,
          name,
          is_default: false,
          is_active: false,
          is_locked: false,
          brand: SETTINGS_BODY.config.ui.brand,
          created_at: null,
          updated_at: null,
        };
        profileList = [...profileList, newProfile];
        return jsonResponse({ ok: true, profile: newProfile });
      }

      if (url.startsWith("/api/settings/ui/brand-assets") && method === "GET") {
        const parsedUrl = new URL(url, "http://localhost");
        const requestedProfile = parsedUrl.searchParams.get("profile_id");
        if (requestedProfile && requestedProfile !== "bp_custom") {
          return jsonResponse({ ok: true, assets: [] });
        }
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
        return jsonResponse({ ok: true, asset: VARIANT_MAP.primary_logo, variants: VARIANT_MAP });
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
    vi.clearAllMocks();
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
    expect(screen.getAllByRole("button", { name: "Delete" })).toHaveLength(1);
    expect(screen.getAllByText("contoso.webp").length).toBeGreaterThan(0);
    expect(screen.getByTestId("auto-managed-secondary_logo")).toHaveTextContent(
      "Managed via asset upload."
    );

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

  it("creates a new branding profile using modal", async () => {
    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );

    await waitFor(() => expect(screen.queryByText("Loading branding settings…")).toBeNull());

    fireEvent.click(screen.getByRole("button", { name: "New profile" }));

    const nameField = await screen.findByLabelText("Profile name");
    const confirmButton = screen.getByRole("button", { name: "Create" });
    expect(confirmButton).toBeDisabled();

    fireEvent.change(nameField, { target: { value: "Marketing" } });
    expect(screen.getByRole("button", { name: "Create" })).toBeEnabled();

    fireEvent.click(screen.getByRole("button", { name: "Create" }));

    await screen.findByText('Profile "Marketing" created. Configure and save to apply.');
    expect(createdProfileName).toBe("Marketing");

    await waitFor(() => expect(screen.queryByLabelText("Profile name")).not.toBeInTheDocument());
    await screen.findByRole("option", { name: "Marketing" });
  });

  it("handles upload validations", async () => {
    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );
    await waitFor(() => expect(screen.queryByText("Loading branding settings…")).toBeNull());

    const fileInput = screen.getByLabelText("Upload asset") as HTMLInputElement;
    const primarySelect = screen.getByLabelText("Primary logo asset selection") as HTMLSelectElement;
    const secondarySelect = screen.getByLabelText("Secondary logo asset selection") as HTMLSelectElement;
    expect(primarySelect.value).toBe("as_primary");
    expect(secondarySelect.value).toBe("as_secondary");

    const file = new File(["png"], "logo.png", { type: "image/png" });

    // trigger upload
    fireEvent.change(fileInput, { target: { files: [file] } });

    await screen.findByText("Upload successful. Select it from the dropdown to apply.");
    expect(uploadBody).not.toBeNull();
    expect(uploadBody?.get("profile_id")).toBe("bp_custom");
    expect(uploadBody?.get("kind")).toBe("primary_logo");
    expect(primarySelect.value).toBe("as_primary");
    expect(secondarySelect.value).toBe("as_secondary");
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

  it("disables branding controls when user only has view access", async () => {
    vi.mocked(getThemeAccess).mockResolvedValueOnce({
      canView: true,
      canManage: false,
      canManagePacks: false,
      roles: ["theme_auditor"],
    });

    render(
      <ToastProvider>
        <BrandingCard />
      </ToastProvider>
    );

    await waitFor(() => expect(screen.queryByText("Loading branding settings…")).toBeNull());

    expect(screen.getByRole("button", { name: "Save" })).toBeDisabled();
    const titleInput = screen.getByLabelText("Title text") as HTMLInputElement;
    expect(titleInput).toBeDisabled();
  });
});
