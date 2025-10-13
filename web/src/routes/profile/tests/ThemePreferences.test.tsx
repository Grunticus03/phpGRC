/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import React from "react";
import ThemePreferences from "../ThemePreferences";
import { ToastProvider } from "../../../components/toast/ToastProvider";
import { DEFAULT_THEME_MANIFEST } from "../../admin/themeData";

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  const headers = new Headers(init.headers ?? {});
  if (!headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  return new Response(JSON.stringify(body), { status: init.status ?? 200, headers });
}

const MANIFEST_BODY = (() => {
  const clone = JSON.parse(JSON.stringify(DEFAULT_THEME_MANIFEST));
  clone.packs = [
    { slug: "pack:ocean", name: "Ocean Pack", source: "pack", supports: { mode: ["light", "dark"] } },
  ];
  return clone;
})();

const GLOBAL_SETTINGS_BODY = {
  ok: true,
  config: {
    ui: {
      theme: {
        default: "slate",
        mode: "dark",
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
};

const USER_PREFS_BODY = {
  ok: true,
  prefs: {
    theme: "darkly",
    mode: "dark" as const,
    overrides: {
      "color.primary": "#345678",
      "color.surface": "#1b1e21",
      "color.text": "#f8f9fa",
      shadow: "light",
      spacing: "wide",
      typeScale: "large",
      motion: "limited",
    },
    sidebar: {
      collapsed: false,
      width: 320,
      order: ["dashboard", "audit"],
    },
  },
};

describe("ThemePreferences", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let fetchMock: ReturnType<typeof vi.fn>;
  let recordedBody: unknown = null;
  let recordedIfMatch: string | null = null;

  beforeEach(() => {
    recordedBody = null;
    recordedIfMatch = null;

    fetchMock = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(MANIFEST_BODY, { headers: { ETag: 'W/"manifest:1"' } });
      }
      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(GLOBAL_SETTINGS_BODY, { headers: { ETag: 'W/"settings:global1"' } });
      }
      if (url === "/api/me/prefs/ui" && method === "GET") {
        return jsonResponse(USER_PREFS_BODY, { headers: { ETag: 'W/"prefs:abc"' } });
      }

      if (url === "/api/me/prefs/ui" && method === "PUT") {
        recordedBody = JSON.parse(String(init.body ?? "{}"));
        const headers = new Headers(init.headers ?? {});
        recordedIfMatch = headers.get("If-Match");
        return jsonResponse(
          {
            ok: true,
            prefs: USER_PREFS_BODY.prefs,
          },
          { headers: { ETag: 'W/"prefs:def"' } }
        );
      }

      return jsonResponse({ ok: true });
    });

    globalThis.fetch = fetchMock as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("loads prefs, saves with If-Match, and respects overrides", async () => {
    render(
      <ToastProvider>
        <ThemePreferences />
      </ToastProvider>
    );

    await waitFor(() => expect(screen.queryByText("Loading preferences…")).toBeNull());

    const themeSelect = screen.getByLabelText("Theme selection") as HTMLSelectElement;
    expect(themeSelect.value).toBe("darkly");
    fireEvent.change(themeSelect, { target: { value: "flatly" } });
    expect(themeSelect.value).toBe("flatly");

    fireEvent.click(screen.getByLabelText("Collapse sidebar by default"));
    const widthSlider = screen.getByLabelText("Sidebar width (px)") as HTMLInputElement;
    fireEvent.change(widthSlider, { target: { value: "200" } });

    const colorInput = screen.getByLabelText(/primary color/i) as HTMLInputElement;
    fireEvent.change(colorInput, { target: { value: "#123456" } });

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText("Preferences saved.");

    expect(recordedIfMatch).toBe('W/"prefs:abc"');
    expect(recordedBody).toMatchObject({
      theme: "flatly",
      overrides: expect.objectContaining({ "color.primary": "#123456" }),
      sidebar: expect.objectContaining({ collapsed: true, width: 200 }),
    });
  });

  it("allows setting mode to follow system", async () => {
    render(
      <ToastProvider>
        <ThemePreferences />
      </ToastProvider>
    );

    await waitFor(() => expect(screen.queryByText("Loading preferences…")).toBeNull());

    const systemRadio = screen.getByLabelText("Follow system") as HTMLInputElement;
    expect(systemRadio.disabled).toBe(false);
    fireEvent.click(systemRadio);

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText("Preferences saved.");

    expect(recordedBody).toMatchObject({
      mode: null,
    });
  });

  it("handles force-global scenario by disabling theme select", async () => {
    fetchMock.mockImplementation(async (...args) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(MANIFEST_BODY, { headers: { ETag: 'W/"manifest:1"' } });
      }
      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(
          {
            ok: true,
            config: {
              ui: {
                ...GLOBAL_SETTINGS_BODY.config.ui,
                theme: {
                  ...GLOBAL_SETTINGS_BODY.config.ui.theme,
                  allow_user_override: false,
                  force_global: true,
                },
              },
            },
          },
          { headers: { ETag: 'W/"settings:global2"' } }
        );
      }
      if (url === "/api/me/prefs/ui" && method === "GET") {
        return jsonResponse(USER_PREFS_BODY, { headers: { ETag: 'W/"prefs:abc"' } });
      }
      if (url === "/api/me/prefs/ui" && method === "PUT") {
        recordedBody = JSON.parse(String((args[1] as RequestInit).body ?? "{}"));
        return jsonResponse({ ok: true }, { headers: { ETag: 'W/"prefs:def"' } });
      }
      return jsonResponse({ ok: true });
    });

    render(
      <ToastProvider>
        <ThemePreferences />
      </ToastProvider>
    );

    await waitFor(() => expect(screen.queryByText("Loading preferences…")).toBeNull());

    const themeSelect = screen.getByLabelText("Theme selection") as HTMLSelectElement;
    expect(themeSelect).toBeDisabled();
    fireEvent.click(screen.getByLabelText("Collapse sidebar by default"));
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText("Preferences saved.");
    expect(recordedBody).toMatchObject({
      theme: null,
    });
  });

  it("handles 409 conflict by reloading", async () => {
    let conflict = true;
    fetchMock.mockImplementation(async (...args) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(MANIFEST_BODY, { headers: { ETag: 'W/"manifest:1"' } });
      }
      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(GLOBAL_SETTINGS_BODY, { headers: { ETag: 'W/"settings:global1"' } });
      }
      if (url === "/api/me/prefs/ui" && method === "GET") {
        return jsonResponse(USER_PREFS_BODY, { headers: { ETag: 'W/"prefs:abc"' } });
      }
      if (url === "/api/me/prefs/ui" && method === "PUT") {
        if (conflict) {
          conflict = false;
          return jsonResponse(
            { ok: false, current_etag: 'W/"prefs:new"' },
            { status: 409, headers: { ETag: 'W/"prefs:new"' } }
          );
        }
        return jsonResponse(USER_PREFS_BODY, { headers: { ETag: 'W/"prefs:def"' } });
      }
      return jsonResponse({ ok: true });
    });

    render(
      <ToastProvider>
        <ThemePreferences />
      </ToastProvider>
    );

    await waitFor(() => expect(screen.queryByText("Loading preferences…")).toBeNull());

    fireEvent.click(screen.getByLabelText("Collapse sidebar by default"));
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText("Preferences changed elsewhere. Reloaded latest values.");
  });

  it("shows read-only message when 403 returned", async () => {
    fetchMock.mockImplementation(async (...args) => {
      const url = String(args[0]);
      const init = (args[1] ?? {}) as RequestInit;
      const method = (init.method ?? "GET").toUpperCase();
      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(MANIFEST_BODY);
      }
      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(GLOBAL_SETTINGS_BODY);
      }
      if (url === "/api/me/prefs/ui" && method === "GET") {
        return jsonResponse({}, { status: 403 });
      }
      return jsonResponse({ ok: true });
    });

    render(
      <ToastProvider>
        <ThemePreferences />
      </ToastProvider>
    );

    await screen.findByText("You do not have permission to manage UI preferences.");
    expect(screen.getByRole("button", { name: "Save" })).toBeDisabled();
  });
});
