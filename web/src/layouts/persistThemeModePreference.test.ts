import { describe, expect, it, vi } from "vitest";
import type { MutableRefObject } from "react";
import { persistThemeModePreference } from "./persistThemeModePreference";
import { DEFAULT_USER_PREFS, type ThemeUserPrefs } from "../routes/admin/themeData";

const clonePrefs = (): ThemeUserPrefs =>
  ({
    ...DEFAULT_USER_PREFS,
    theme: "slate",
    mode: "light",
    overrides: { ...DEFAULT_USER_PREFS.overrides },
    sidebar: { ...DEFAULT_USER_PREFS.sidebar },
  } as unknown as ThemeUserPrefs);

const createRef = (value: string | null): MutableRefObject<string | null> =>
  ({ current: value } as MutableRefObject<string | null>);

const headersFrom = (headers?: HeadersInit): Record<string, string> => {
  if (!headers) return {};
  if (headers instanceof Headers) return Object.fromEntries(headers.entries());
  if (Array.isArray(headers)) return Object.fromEntries(headers);
  return { ...(headers as Record<string, string>) };
};

describe("persistThemeModePreference", () => {
  it("skips persistence when not authenticated or overrides disabled", async () => {
    const fetchImpl = vi.fn();
    await persistThemeModePreference("dark", {
      authed: false,
      allowOverride: true,
      etagRef: createRef("etag"),
      loadUserPrefs: vi.fn(),
      getPrefs: clonePrefs,
      updatePrefs: vi.fn(),
      fetchImpl,
    });
    await persistThemeModePreference("dark", {
      authed: true,
      allowOverride: false,
      etagRef: createRef("etag"),
      loadUserPrefs: vi.fn(),
      getPrefs: clonePrefs,
      updatePrefs: vi.fn(),
      fetchImpl,
    });
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it("acquires an ETag before saving when missing", async () => {
    const etagRef = createRef(null);
    const loadUserPrefs = vi.fn().mockImplementation(async (options?: { skipStateUpdate?: boolean }) => {
      if (options?.skipStateUpdate) {
        etagRef.current = 'W/"from-load"';
      }
    });
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ prefs: clonePrefs(), etag: 'W/"next"' }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    );
    const updatePrefs = vi.fn();

    await persistThemeModePreference("dark", {
      authed: true,
      allowOverride: true,
      etagRef,
      loadUserPrefs,
      getPrefs: clonePrefs,
      updatePrefs,
      fetchImpl,
    });

    expect(loadUserPrefs).toHaveBeenCalledWith({ skipStateUpdate: true });
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const init = (fetchImpl.mock.calls[0]?.[1] ?? {}) as RequestInit;
    const headers = headersFrom(init.headers);
    expect(headers?.["If-Match"]).toBe('W/"from-load"');
  });

  it("persists the mode preference and updates local cache", async () => {
    const etagRef = createRef('W/"etag"');
    const basePrefs = clonePrefs();
    const serverPrefs = { ...basePrefs, mode: "dark" } as ThemeUserPrefs;
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ prefs: serverPrefs, etag: 'W/"server"' }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    );
    const updatePrefs = vi.fn();
    const getPrefs = vi.fn(() => clonePrefs());

    await persistThemeModePreference("dark", {
      authed: true,
      allowOverride: true,
      etagRef,
      loadUserPrefs: vi.fn(),
      getPrefs,
      updatePrefs,
      fetchImpl,
    });

    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const [url, rawInit] = fetchImpl.mock.calls[0] ?? [];
    expect(url).toBe("/me/prefs/ui");
    const init = (rawInit ?? {}) as RequestInit;
    const headers = headersFrom(init.headers);
    expect(init.method).toBe("PUT");
    expect(headers?.["If-Match"]).toBe('W/"etag"');
    expect(headers?.Accept).toBe("application/json");
    expect(JSON.parse((init.body ?? "{}") as string)).toMatchObject({ mode: "dark" });
    expect(etagRef.current).toBe('W/"server"');
    expect(updatePrefs).toHaveBeenCalledWith(serverPrefs);
  });

  it("reloads preferences when the server reports a conflict", async () => {
    const etagRef = createRef(null);
    const loadUserPrefs = vi
      .fn()
      .mockImplementation(async (options?: { skipStateUpdate?: boolean }) => {
        if (options?.skipStateUpdate) {
          etagRef.current = 'W/"from-load"';
        }
      });
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ current_etag: 'W/"conflict"' }), {
        status: 409,
        headers: { "Content-Type": "application/json" },
      })
    );
    const updatePrefs = vi.fn();

    await persistThemeModePreference("light", {
      authed: true,
      allowOverride: true,
      etagRef,
      loadUserPrefs,
      getPrefs: clonePrefs,
      updatePrefs,
      fetchImpl,
    });

    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(loadUserPrefs).toHaveBeenNthCalledWith(1, { skipStateUpdate: true });
    expect(loadUserPrefs).toHaveBeenCalledTimes(2);
    expect(loadUserPrefs.mock.calls[1]).toEqual([]);
    expect(etagRef.current).toBe('W/"conflict"');
    expect(updatePrefs).not.toHaveBeenCalled();
  });
});
