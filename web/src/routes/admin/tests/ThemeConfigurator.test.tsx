/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import React from "react";
import { MemoryRouter } from "react-router-dom";
import ThemeConfigurator from "../ThemeConfigurator";
import { DEFAULT_THEME_MANIFEST } from "../themeData";

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  const headers = new Headers(init.headers ?? {});
  if (!headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  return new Response(JSON.stringify(body), { status: init.status ?? 200, headers });
}

describe("ThemeConfigurator", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let recordedBody: unknown = null;
  let recordedIfMatch: string | null = null;
  let fetchCalls: Array<[RequestInfo | URL, RequestInit | undefined]> = [];

  beforeEach(() => {
    recordedBody = null;
    recordedIfMatch = null;
    fetchCalls = [];

    globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
      fetchCalls.push([args[0], args[1]]);
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:abc"' } });
      }

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(
          {
            ok: true,
            config: {
              ui: {
                theme: {
                  default: "slate",
                  allow_user_override: true,
                  force_global: false,
                  overrides: {
                    "color.primary": "#0d6efd",
                    "color.surface": "#1b1e21",
                    "color.text": "#f8f9fa",
                    shadow: "default",
                    spacing: "default",
                    typeScale: "medium",
                    motion: "full",
                  },
                },
                nav: { sidebar: { default_order: [] } },
                brand: {
                  title_text: "phpGRC — Dashboard",
                  favicon_asset_id: null,
                  primary_logo_asset_id: null,
                  secondary_logo_asset_id: null,
                  header_logo_asset_id: null,
                  footer_logo_asset_id: null,
                  footer_logo_disabled: false,
                },
              },
            },
          },
          { headers: { ETag: 'W/"settings:etag1"' } }
        );
      }

      if (url === "/api/settings/ui" && method === "PUT") {
        try {
          recordedBody = JSON.parse(String(init.body ?? "{}"));
        } catch {
          recordedBody = null;
        }
        const headers = new Headers(init.headers ?? {});
        recordedIfMatch = headers.get("If-Match");

        return jsonResponse(
          {
            ok: true,
            config: {
              ui: {
                theme: {
                  default: "flatly",
                  allow_user_override: false,
                  force_global: true,
                  overrides: {
                    "color.primary": "#ff0000",
                    "color.surface": "#1b1e21",
                    "color.text": "#f8f9fa",
                    shadow: "light",
                    spacing: "wide",
                    typeScale: "large",
                    motion: "limited",
                  },
                },
                nav: { sidebar: { default_order: [] } },
                brand: DEFAULT_BRAND_STATE,
              },
            },
          },
          { headers: { ETag: 'W/"settings:etag2"' } }
        );
      }

      return jsonResponse({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("loads manifest, saves with If-Match, and updates state", async () => {
    render(
      <MemoryRouter>
        <ThemeConfigurator />
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.queryByText("Loading theme settings…")).toBeNull());

    const designerLink = screen.getByRole("link", { name: "Theme Designer" });
    expect(designerLink).toHaveAttribute("href", "/admin/settings/theme-designer");

    const themeSelect = screen.getByLabelText("Default theme") as HTMLSelectElement;
    expect(themeSelect.value).toBe("slate");
    fireEvent.change(themeSelect, { target: { value: "flatly" } });
    expect(themeSelect.value).toBe("flatly");

    const allowToggle = screen.getByLabelText("Allow user theme override") as HTMLInputElement;
    fireEvent.click(allowToggle);
    expect(allowToggle.checked).toBe(false);

    const forceToggle = screen.getByLabelText(
      "Force global theme (light/dark still follows capability rules)"
    ) as HTMLInputElement;
    fireEvent.click(forceToggle);
    expect(forceToggle.checked).toBe(true);

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText("Theme settings saved.");

    expect(recordedIfMatch).toBe('W/"settings:etag1"');
    expect(recordedBody).toMatchInlineSnapshot(`
      {
        "ui": {
          "theme": {
            "allow_user_override": false,
            "default": "flatly",
            "force_global": true,
            "overrides": {
              "color.primary": "#0d6efd",
              "color.surface": "#1b1e21",
              "color.text": "#f8f9fa",
              "motion": "full",
              "shadow": "default",
              "spacing": "default",
              "typeScale": "medium",
            },
          },
        },
      }
    `);
  });

  it("handles 409 conflicts by reloading settings", async () => {
    let conflictReturned = false;
    const fetchMock = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY);
      }
      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag1"' } });
      }
      if (url === "/api/settings/ui" && method === "PUT") {
        if (!conflictReturned) {
          conflictReturned = true;
          return jsonResponse(
            { ok: false, current_etag: 'W/"settings:etaglatest"' },
            { status: 409, headers: { ETag: 'W/"settings:etaglatest"' } }
          );
        }
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag2"' } });
      }
      return jsonResponse({ ok: true });
    });

    globalThis.fetch = fetchMock as unknown as typeof fetch;

    render(
      <MemoryRouter>
        <ThemeConfigurator />
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.queryByText("Loading theme settings…")).toBeNull());

    fireEvent.click(screen.getByLabelText("Allow user theme override"));

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText("Settings changed elsewhere. Reloaded latest values.");

    const getCalls = fetchMock.mock.calls.filter(
      ([url, init]) =>
        String(url) === "/api/settings/ui" && ((init as RequestInit | undefined)?.method ?? "GET") === "GET"
    );
    expect(getCalls.length).toBeGreaterThanOrEqual(2);
  });

  it("shows read-only message when GET /settings/ui returns 403", async () => {
    const fetchMock = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();
      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY);
      }
      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse({}, { status: 403 });
      }
      return jsonResponse({ ok: true });
    });
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    render(
      <MemoryRouter>
        <ThemeConfigurator />
      </MemoryRouter>
    );

    await screen.findByText("You do not have permission to adjust theme settings.");

    const saveButton = screen.getByRole("button", { name: "Save" });
    expect(saveButton).toBeDisabled();
  });
});

const DEFAULT_BRAND_STATE = {
  title_text: "phpGRC — Dashboard",
  favicon_asset_id: null,
  primary_logo_asset_id: null,
  secondary_logo_asset_id: null,
  header_logo_asset_id: null,
  footer_logo_asset_id: null,
  footer_logo_disabled: false,
};

const DEFAULT_MANIFEST_BODY = JSON.parse(JSON.stringify(DEFAULT_THEME_MANIFEST));

const DEFAULT_SETTINGS_BODY = {
  ok: true,
  config: {
    ui: {
      theme: {
        default: "slate",
        allow_user_override: true,
        force_global: false,
        overrides: {
          "color.primary": "#0d6efd",
          "color.surface": "#1b1e21",
          "color.text": "#f8f9fa",
          shadow: "default",
          spacing: "default",
          typeScale: "medium",
          motion: "full",
        },
      },
      nav: { sidebar: { default_order: [] } },
      brand: DEFAULT_BRAND_STATE,
    },
  },
};
