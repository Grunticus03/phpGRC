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

import ThemeConfigurator from "../ThemeConfigurator";
import { ToastProvider } from "../../../components/toast/ToastProvider";
import { DEFAULT_THEME_MANIFEST, DEFAULT_THEME_SETTINGS } from "../themeData";

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
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
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

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:1"' } });
      }

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag1"' } });
      }

      if (url === "/api/settings/ui" && method === "PUT") {
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

    const putCall = calls.find((call) => call.method === "PUT" && call.url === "/api/settings/ui");
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

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:login"' } });
      }

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:login1"' } });
      }

      if (url === "/api/settings/ui" && method === "PUT") {
        return jsonResponse(
          {
            ok: true,
            config: {
              ui: {
                ...DEFAULT_SETTINGS_BODY.config.ui,
                theme: {
                  ...DEFAULT_SETTINGS_BODY.config.ui.theme,
                  login: { layout: "layout_2" },
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

    const layout2Option = screen.getByLabelText("Layout 2") as HTMLInputElement;
    expect(layout2Option.checked).toBe(false);
    fireEvent.click(layout2Option);
    expect(layout2Option.checked).toBe(true);

    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await screen.findByText(SUCCESS_TOAST, {}, { timeout: 4000 });

    const putCall = calls.find((call) => call.method === "PUT" && call.url === "/api/settings/ui");
    expect(putCall).toBeTruthy();
    const payload = putCall?.init.body ? JSON.parse(String(putCall.init.body)) : null;
    expect(payload?.ui?.theme?.login).toMatchObject({ layout: "layout_2" });
  });

  it("allows selecting a default mode", async () => {
    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY, { headers: { ETag: 'W/"manifest:2"' } });
      }

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: 'W/"settings:etag-light"' } });
      }

      if (url === "/api/settings/ui" && method === "PUT") {
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

    const putCall = calls.find((call) => call.method === "PUT" && call.url === "/api/settings/ui");
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

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY);
      }

      if (url === "/api/settings/ui" && method === "GET") {
        return jsonResponse(DEFAULT_SETTINGS_BODY, { headers: { ETag: conflict ? 'W/"settings:etag1"' : 'W/"settings:etag2"' } });
      }

      if (url === "/api/settings/ui" && method === "PUT") {
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

    const putCalls = calls.filter((call) => call.method === "PUT" && call.url === "/api/settings/ui");
    expect(putCalls.length).toBeGreaterThanOrEqual(1);
    const followUpGet = calls.filter((call) => call.method === "GET" && call.url === "/api/settings/ui");
    expect(followUpGet.length).toBeGreaterThanOrEqual(2);
  });

  it("enters read-only mode when the settings endpoint returns 403", async () => {
    installFetch(async (_input, init) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof _input === "string" ? _input : _input.toString();

      if (url === "/api/settings/ui/themes" && method === "GET") {
        return jsonResponse(DEFAULT_MANIFEST_BODY);
      }

      if (url === "/api/settings/ui" && method === "GET") {
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
});
