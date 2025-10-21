import type { MutableRefObject } from "react";
import { baseHeaders } from "../lib/api";
import type { ThemeUserPrefs } from "../routes/admin/themeData";

type ThemeMode = "light" | "dark";

type LoadUserPrefsOptions = { skipStateUpdate?: boolean };

export type PersistThemeModeOptions = {
  authed: boolean;
  allowOverride: boolean;
  etagRef: MutableRefObject<string | null>;
  loadUserPrefs: (options?: LoadUserPrefsOptions) => Promise<void>;
  getPrefs: () => ThemeUserPrefs;
  updatePrefs: (prefs: ThemeUserPrefs) => void;
  fetchImpl?: typeof fetch;
};

type PersistResponse = {
  prefs?: ThemeUserPrefs;
  etag?: string | null;
  current_etag?: string | null;
};

const parseJson = async <T>(res: Response): Promise<T | null> => {
  try {
    return (await res.json()) as T;
  } catch {
    return null;
  }
};

export const persistThemeModePreference = async (
  mode: ThemeMode,
  options: PersistThemeModeOptions
): Promise<void> => {
  const {
    authed,
    allowOverride,
    etagRef,
    loadUserPrefs,
    getPrefs,
    updatePrefs,
    fetchImpl,
  } = options;

  if (!authed || !allowOverride) return;

  const fetcher = fetchImpl ?? fetch;

  if (!etagRef.current) {
    try {
      await loadUserPrefs({ skipStateUpdate: true });
    } catch {
      // ignore fetch failures while attempting to acquire etag
    }
  }

  const prefs = getPrefs();
  const requestBody = {
    ...prefs,
    mode,
  } as ThemeUserPrefs;

  let res: Response;
  try {
    res = await fetcher("/me/prefs/ui", {
      method: "PUT",
      credentials: "same-origin",
      headers: baseHeaders({
        "Content-Type": "application/json",
        "If-Match": etagRef.current ?? "",
      }),
      body: JSON.stringify(requestBody),
    });
  } catch {
    return;
  }

  const body = await parseJson<PersistResponse>(res);
  const resolveEtag = (): string | null =>
    res.headers.get("ETag") ?? (body?.etag ?? null);

  if (res.status === 409) {
    etagRef.current = body?.current_etag ?? resolveEtag();
    try {
      await loadUserPrefs();
    } catch {
      // ignore reload failures after conflict
    }
    return;
  }

  if (res.status === 403) {
    etagRef.current = resolveEtag();
    try {
      await loadUserPrefs();
    } catch {
      // ignore reload failures after permission change
    }
    return;
  }

  if (!res.ok) {
    return;
  }

  etagRef.current = resolveEtag();

  if (body?.prefs) {
    updatePrefs(body.prefs);
  }
};

