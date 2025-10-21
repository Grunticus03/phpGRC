/** @vitest-environment jsdom */
import React from "react";
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import {
  fireEvent,
  render,
  screen,
  waitForElementToBeRemoved,
} from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

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

import ThemeConfigurator from "../ThemeConfigurator";
import { ToastProvider } from "../../../components/toast/ToastProvider";
import { DEFAULT_THEME_MANIFEST, DEFAULT_THEME_SETTINGS } from "../themeData";
import { getThemeAccess } from "../../../lib/themeAccess";

type FetchCall = {
  url: string;
  method: string;
  init: RequestInit;
};

const ROUTER_FUTURE_FLAGS = { v7_startTransition: true, v7_relativeSplatPath: true } as const;
const SUCCESS_TOAST = "Theme settings saved.";
const CONFLICT_TOAST = "Settings changed elsewhere. Reloaded latest values.";
const FORBIDDEN_TOAST = "You do not have permission to adjust theme settings.";

const jsonResponse = (body: unknown, init: ResponseInit = {}) => {
  const headers = new Headers(init.headers ?? {});
  if (!headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers,
  });
};

const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

const DEFAULT_MANIFEST_BODY = clone(DEFAULT_THEME_MANIFEST);
const DEFAULT_SETTINGS_BODY = {
  ok: true,
  config: {
    ui: clone(DEFAULT_THEME_SETTINGS),
  },
};

const renderConfigurator = () =>
  render(
    <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
      <ToastProvider>
        <ThemeConfigurator />
      </ToastProvider>
    </MemoryRouter>
  );

describe("ThemeConfigurator", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  let calls: FetchCall[];

  beforeEach(() => {
    calls = [];
    vi.mocked(getThemeAccess).mockResolvedValue({
      canView: true,
      canManage: true,
      canManagePacks: true,
      roles: ["admin"],
    });
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.clearAllMocks();
  });

  const installFetch = (impl: (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>) => {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const requestInit: RequestInit = init ?? {};
      const method = (requestInit.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : input.toString();
      calls.push({ url, method, init: requestInit });
      return impl(input, init);
    }) as unknown as typeof fetch;
  };

  const waitForLoadingToExit = async () => {
    await waitForElementToBeRemoved(() => screen.queryByText("Loading theme settings…"), {
      timeout: 4000,
    });
  };

  it("submits updated theme settings with the current ETag", async () => {
    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:1"' } });
      }

      if (url === "/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag1"' } });
      }

      if (url === "/settings/ui" && method === "PUT") {
        return jsonResponse(
          {
            ok: true,
            config: {
              ui: {
                ...DEFAULT_SETTINGS_BODY.config.ui,
                theme: {
                  ...DEFAULT_SETTINGS_BODY.config.ui.theme,
                  default: "flatly",
                  force_global: true,
                  allow_user_override: false,
                },
              },
            },
          },
          { headers: { ETag: 'W/"settings:etag2"' } }
        );
      }

      return jsonResponse({ ok: true });
    });

    renderConfigurator();

    await waitForLoadingToExit();

    const themeSelect = await screen.findByLabelText("Default theme");
    fireEvent.change(themeSelect, { target: { value: "flatly" } });

    const forceToggle = screen.getByLabelText(
      "Force global theme (light/dark still follows capability rules)"
    ) as HTMLInputElement;
    fireEvent.click(forceToggle);

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText(SUCCESS_TOAST, {}, { timeout: 4000 });

    const putCall = calls.find((call) => call.method === "PUT" && call.url === "/settings/ui");
    expect(putCall).toBeTruthy();
    expect(putCall?.init.headers instanceof Headers ? putCall.init.headers.get("If-Match") : new Headers(putCall?.init.headers ?? {}).get("If-Match")).toBe('W/"settings:etag1"');

    const payload = putCall?.init.body ? JSON.parse(String(putCall.init.body)) : null;
    expect(payload).toMatchObject({
      ui: {
        theme: {
          default: "flatly",
          mode: "dark",
          force_global: true,
          allow_user_override: false,
          login: {
            layout: "layout_1",
          },
        },
      },
    });
  });

  it("persists the selected login layout", async () => {
    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:login"' } });
      }

      if (url === "/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:login1"' } });
      }

      if (url === "/settings/ui" && method === "PUT") {
        return jsonResponse(
          {
            ok: true,
            config: {
              ui: {
                ...DEFAULT_SETTINGS_BODY.config.ui,
                theme: {
                  ...DEFAULT_SETTINGS_BODY.config.ui.theme,
                  login: { layout: "layout_3" },
                },
              },
            },
          },
          { headers: { ETag: 'W/"settings:login2"' } }
        );
      }

      return jsonResponse({ ok: true });
    });

    renderConfigurator();

    await waitForLoadingToExit();

    const layout3Option = screen.getByLabelText("Layout 3") as HTMLInputElement;
    expect(layout3Option.checked).toBe(false);
    fireEvent.click(layout3Option);
    expect(layout3Option.checked).toBe(true);

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText(SUCCESS_TOAST, {}, { timeout: 4000 });

    const putCall = calls.find((call) => call.method === "PUT" && call.url === "/settings/ui");
    expect(putCall).toBeTruthy();
    const payload = putCall?.init.body ? JSON.parse(String(putCall.init.body)) : null;
    expect(payload?.ui?.theme?.login).toMatchObject({ layout: "layout_3" });
  });

  it("allows selecting a default mode", async () => {
    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:2"' } });
      }

      if (url === "/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag-light"' } });
      }

      if (url === "/settings/ui" && method === "PUT") {
        return jsonResponse(
          {
            ok: true,
            config: {
              ui: {
                ...DEFAULT_SETTINGS_BODY.config.ui,
                theme: {
                  ...DEFAULT_SETTINGS_BODY.config.ui.theme,
                  default: "flatly",
                  mode: "light",
                },
              },
            },
          },
          { headers: { ETag: 'W/"settings:etag-light-2"' } }
        );
      }

      return jsonResponse({ ok: true });
    });

    renderConfigurator();

    await waitForLoadingToExit();

    const themeSelect = await screen.findByLabelText("Default theme");
    fireEvent.change(themeSelect, { target: { value: "flatly" } });

    const lightOption = screen.getByLabelText("Primary") as HTMLInputElement;
    expect(lightOption.disabled).toBe(false);
    fireEvent.click(lightOption);

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText(SUCCESS_TOAST, {}, { timeout: 4000 });

    const putCall = calls.find((call) => call.method === "PUT" && call.url === "/settings/ui");
    const payload = putCall?.init.body ? JSON.parse(String(putCall.init.body)) : null;
    expect(payload).toMatchObject({
      ui: {
        theme: {
          default: "flatly",
          mode: "light",
          login: {
            layout: "layout_1",
          },
        },
      },
    });
  });

  it("shows a conflict message and refetches when the save returns 409", async () => {
    let conflict = true;

    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY);
      }

      if (url === "/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: conflict ? 'W/"settings:etag1"' : 'W/"settings:etag2"' } });
      }

      if (url === "/settings/ui" && method === "PUT") {
        if (conflict) {
          conflict = false;
          return jsonResponse(
            { ok: false, current_etag: 'W/"settings:new"' },
            { status: 409, headers: { ETag: 'W/"settings:new"' } }
          );
        }

        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag3"' } });
      }

      return jsonResponse({ ok: true });
    });

    renderConfigurator();

    await waitForLoadingToExit();

    const themeSelect = await screen.findByLabelText("Default theme");
    fireEvent.change(themeSelect, { target: { value: "cosmo" } });

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText(CONFLICT_TOAST, {}, { timeout: 4000 });

    const putCalls = calls.filter((call) => call.method === "PUT" && call.url === "/settings/ui");
    expect(putCalls.length).toBeGreaterThanOrEqual(1);
    const followUpGet = calls.filter((call) => call.method === "GET" && call.url === "/settings/ui");
    expect(followUpGet.length).toBeGreaterThanOrEqual(2);
  });

  it("enters read-only mode when the settings endpoint returns 403", async () => {
    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY);
      }

      if (url === "/settings/ui" && method === "GET") {
        return jsonResponse({}, { status: 403 });
      }

      return jsonResponse({ ok: true });
    });

    renderConfigurator();

    await waitForElementToBeRemoved(() => screen.queryByText("Loading theme settings…"), {
      timeout: 4000,
    });

    await screen.findByText(FORBIDDEN_TOAST, {}, { timeout: 4000 });

    const saveButton = screen.getByRole("button", { name: "Save" });
    expect(saveButton).toBeDisabled();
  });

  it("disables controls when user only has view access", async () => {
    vi.mocked(getThemeAccess).mockResolvedValueOnce({
      canView: true,
      canManage: false,
      canManagePacks: false,
      roles: ["theme_auditor"],
    });

    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:1"' } });
      }

      if (url === "/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag1"' } });
      }

      return jsonResponse({ ok: true });
    });

    renderConfigurator();

    await waitForLoadingToExit();

    const saveButton = screen.getByRole("button", { name: "Save" });
    expect(saveButton).toBeDisabled();

    const themeSelect = screen.getByLabelText("Default theme") as HTMLSelectElement;
    expect(themeSelect).toBeDisabled();
  });
});
