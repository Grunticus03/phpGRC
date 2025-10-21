import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import {
  useCallback,
  useEffect,
  useMemo,
  useLayoutEffect,
  useRef,
  useState,
  type CSSProperties,
  type FocusEvent as ReactFocusEvent,
  type MouseEvent as ReactMouseEvent,
} from "react";
import type { PointerEvent as ReactPointerEvent } from "react";
import {
  authLogout,
  authMe,
  baseHeaders,
  hasAuthToken,
  markSessionExpired,
  onUnauthorized,
  rememberIntendedPath,
  apiGet,
} from "../lib/api";
import { seedThemeRequireAuth } from "../lib/themeAccess";
import {
  bootstrapTheme,
  getBrandAssetUrl,
  getCachedThemePrefs,
  getCachedThemeSettings,
  getCachedThemeManifest,
  getCurrentTheme,
  onThemePrefsChange,
  onThemeSettingsChange,
  onThemeManifestChange,
  updateThemePrefs,
  updateThemeSettings,
  toggleThemeMode,
} from "../theme/themeManager";
import {
  NAVBAR_MODULES,
  MODULE_LOOKUP,
  SIDEBAR_MODULES,
  type ModuleMeta,
} from "./modules";
import "./AppLayout.css";
import {
  MIN_SIDEBAR_WIDTH,
  clampSidebarWidth,
  mergeSidebarOrder,
} from "./sidebarUtils";
import {
  DEFAULT_THEME_SETTINGS,
  DEFAULT_USER_PREFS,
  type ThemeManifest,
  type ThemeSettings,
  type ThemeUserPrefs,
} from "../routes/admin/themeData";
import { ToastProvider } from "../components/toast/ToastProvider";
import { persistThemeModePreference } from "./persistThemeModePreference";

type Fingerprint = {
  summary?: { rbac?: { require_auth?: boolean } };
};

type BrandSnapshot = {
  title: string;
  headerLogoId: string | null;
  primaryLogoId: string | null;
};

type SidebarNotice = {
  text: string;
  tone: "info" | "error";
  ephemeral?: boolean;
};

type LoadUserPrefsOptions = {
  skipStateUpdate?: boolean;
};

type AdminNavLeaf = {
  id: string;
  label: string;
  to?: string;
  href?: string;
};

type AdminNavItem = AdminNavLeaf & {
  children?: readonly AdminNavLeaf[];
};

type UiSettingsResponse = { config?: { ui?: ThemeSettings } };
type UserPrefsResponse = {
  prefs?: ThemeUserPrefs;
  etag?: string | null;
  current_etag?: string | null;
  message?: string;
};

const DEFAULT_LOGO_SRC = "/images/phpGRC-light-horizontal-trans.webp";
const LONG_PRESS_DURATION_MS = 600;
const MAX_PRIMARY_MODULES = 19;
const OVERFLOW_CHUNK_SIZE = 20;
const VISIBLE_DRAG_END_ID = "__visible_end__";
const HIDDEN_DRAG_END_ID = "__hidden_end__";
const DASHBOARD_TOGGLE_EDIT_MODE_EVENT = "dashboard-toggle-edit-mode";
const DASHBOARD_EDIT_MODE_STATE_EVENT = "dashboard-edit-mode-state";
const PAGE_FADE_DURATION_MS = 180;

const ADMIN_NAV_ITEMS: readonly AdminNavItem[] = [
  {
    id: "admin.settings",
    label: "Settings",
    to: "/admin/settings/core",
    children: [
      { id: "admin.settings.theming", label: "Theme", to: "/admin/settings/theming" },
      { id: "admin.settings.branding", label: "Branding", to: "/admin/settings/branding" },
      { id: "admin.settings.core", label: "Core Settings", to: "/admin/settings/core" },
    ],
  },
  { id: "admin.roles", label: "Roles", to: "/admin/roles" },
  { id: "admin.idp", label: "Identity Providers", to: "/admin/idp/providers" },
  { id: "admin.users", label: "Users", to: "/admin/users" },
  { id: "admin.audit", label: "Audit Logs", to: "/admin/audit" },
  { id: "admin.api-docs", label: "API Docs", href: "/api-docs/" },
];

type SidebarPrefs = {
  collapsed: boolean;
  width: number;
  pinned: boolean;
  order: string[];
  hidden: string[];
};

const arraysEqual = (a: readonly string[], b: readonly string[]): boolean => {
  if (a.length !== b.length) return false;
  return a.every((value, index) => value === b[index]);
};

async function parseJson<T>(res: Response): Promise<T | null> {
  try {
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

const extractSidebarDefaultOrder = (settings: ThemeSettings | null | undefined): string[] => {
  const source = settings?.nav?.sidebar?.default_order as unknown;
  if (!Array.isArray(source)) return [];
  const order: string[] = [];
  for (const item of source as unknown[]) {
    if (typeof item !== "string") continue;
    const token = item.trim();
    if (token !== "") order.push(token);
  }
  return order;
};

const computeBrandSnapshot = (settings: ThemeSettings | null | undefined): BrandSnapshot => {
  const brand = (settings?.brand ?? DEFAULT_THEME_SETTINGS.brand) as ThemeSettings["brand"];
  const titleCandidate = brand?.title_text;
  const trimmed = String(titleCandidate ?? DEFAULT_THEME_SETTINGS.brand.title_text).trim();
  const title = trimmed !== "" ? trimmed : DEFAULT_THEME_SETTINGS.brand.title_text;
  const headerLogo =
    typeof brand?.header_logo_asset_id === "string" ? brand.header_logo_asset_id : null;
  const primaryLogo =
    typeof brand?.primary_logo_asset_id === "string" ? brand.primary_logo_asset_id : null;
  return { title, headerLogoId: headerLogo, primaryLogoId: primaryLogo };
};

const brandSnapshotsEqual = (a: BrandSnapshot, b: BrandSnapshot): boolean =>
  a.title === b.title && a.headerLogoId === b.headerLogoId && a.primaryLogoId === b.primaryLogoId;

const resolveLogoSrc = (brand: BrandSnapshot, failed?: Set<string>): string => {
  const headerId = brand.headerLogoId;
  if (headerId && !failed?.has(headerId)) {
    const headerUrl = getBrandAssetUrl(headerId);
    if (headerUrl) {
      return headerUrl;
    }
  }
  const primaryId = brand.primaryLogoId;
  if (primaryId && !failed?.has(primaryId)) {
    const primaryUrl = getBrandAssetUrl(primaryId);
    if (primaryUrl) {
      return primaryUrl;
    }
  }
  return DEFAULT_LOGO_SRC;
};

const normalizeSidebarPrefs = (prefs?: ThemeUserPrefs["sidebar"] | SidebarPrefs): SidebarPrefs => {
  const source = prefs ?? DEFAULT_USER_PREFS.sidebar;
  const collapsed = Boolean(source.collapsed);
  const pinnedRaw = (source as { pinned?: unknown }).pinned;
  const pinned = (() => {
    if (pinnedRaw === false) return false;
    if (pinnedRaw === true) return true;
    if (pinnedRaw === null || pinnedRaw === undefined) return true;
    if (typeof pinnedRaw === "number") {
      return pinnedRaw !== 0;
    }
    if (typeof pinnedRaw === "string") {
      const token = pinnedRaw.trim().toLowerCase();
      if (token === "0" || token === "false" || token === "off" || token === "no") return false;
      if (token === "1" || token === "true" || token === "on" || token === "yes") return true;
    }
    return true;
  })();
  const widthRaw = typeof source.width === "number" ? source.width : DEFAULT_USER_PREFS.sidebar.width;
  const width = clampSidebarWidth(widthRaw);
  const orderSet = new Set<string>();
  const orderRaw = Array.isArray(source.order)
    ? source.order
        .map((value) => (typeof value === "string" ? value.trim() : ""))
        .filter((value) => {
          if (value === "" || orderSet.has(value)) return false;
          orderSet.add(value);
          return true;
        })
    : [];

  const hiddenSource = (source as { hidden?: unknown }).hidden;
  const hiddenSet = new Set<string>();
  const hidden = Array.isArray(hiddenSource)
    ? hiddenSource
        .map((value) => (typeof value === "string" ? value.trim() : ""))
        .filter((value) => {
          if (value === "" || hiddenSet.has(value)) return false;
          hiddenSet.add(value);
          return true;
        })
    : [];

  const filteredOrder = orderRaw.filter((value) => !hiddenSet.has(value));

  return { collapsed, width, pinned, order: filteredOrder, hidden: [...hidden] };
};

const chunk = <T,>(items: readonly T[], size: number): T[][] => {
  if (size <= 0) return [];
  const result: T[][] = [];
  for (let index = 0; index < items.length; index += size) {
    result.push(items.slice(index, index + size));
  }
  return result;
};

export default function AppLayout(): JSX.Element | null {
  const loc = useLocation();
  const navigate = useNavigate();
  const locationRef = useRef(loc);

  const [loading, setLoading] = useState(true);
  const [requireAuth, setRequireAuth] = useState(false);
  const [authed, setAuthed] = useState(false);

  const [brand, setBrand] = useState<BrandSnapshot>(() =>
    computeBrandSnapshot(getCachedThemeSettings())
  );
  const [manifest, setManifest] = useState<ThemeManifest>(() => getCachedThemeManifest());
  const [sidebarDefaultOrder, setSidebarDefaultOrder] = useState<string[]>(() =>
    extractSidebarDefaultOrder(getCachedThemeSettings())
  );
  const [sidebarPrefs, setSidebarPrefs] = useState<SidebarPrefs>(() =>
    normalizeSidebarPrefs(getCachedThemePrefs().sidebar as SidebarPrefs)
  );
  const sidebarPrefsRef = useRef<SidebarPrefs>(sidebarPrefs);
  useEffect(() => {
    sidebarPrefsRef.current = sidebarPrefs;
  }, [sidebarPrefs]);

  const sidebarEtagRef = useRef<string | null>(null);
  const uiSettingsEtagRef = useRef<string | null>(null);

  const [sidebarNotice, setSidebarNotice] = useState<SidebarNotice | null>(null);
  const [sidebarSaving, setSidebarSaving] = useState<boolean>(false);
  const [sidebarReadOnly, setSidebarReadOnly] = useState<boolean>(false);
  const [prefsLoading, setPrefsLoading] = useState<boolean>(false);
  const [sidebarToastFading, setSidebarToastFading] = useState(false);
  const sidebarFadeTimer = useRef<number | null>(null);
  const sidebarHideTimer = useRef<number | null>(null);
  const sidebarRef = useRef<HTMLElement | null>(null);
  const sidebarHoverZoneRef = useRef<HTMLElement | null>(null);
  const pageFadeRef = useRef<HTMLElement | null>(null);
  const [sidebarToggleHovering, setSidebarToggleHovering] = useState(false);

  const [customizing, setCustomizing] = useState<boolean>(false);
  const customizingRef = useRef(false);
  useEffect(() => {
    customizingRef.current = customizing;
  }, [customizing]);

  useEffect(() => {
    if (!customizing) {
      setSidebarOverflowOpen(false);
    }
  }, [customizing]);

  useLayoutEffect(() => {
    if (typeof window === "undefined") return;
    const node = pageFadeRef.current;
    if (!node) return;
    node.classList.remove("page-fade-animating");
    // Force a reflow so the animation can restart when navigating quickly.
    void node.offsetWidth;
    node.classList.add("page-fade-animating");
    const timer = window.setTimeout(() => {
      node.classList.remove("page-fade-animating");
    }, PAGE_FADE_DURATION_MS);
    return () => window.clearTimeout(timer);
  }, [loc.pathname, loc.search, loc.hash]);

  const [sidebarOverflowOpen, setSidebarOverflowOpen] = useState(false);
  const overflowContainerRef = useRef<HTMLDivElement | null>(null);
  const overflowMenuRef = useRef<HTMLDivElement | null>(null);
  const overflowTriggerRef = useRef<HTMLButtonElement | null>(null);

  const [editingOrder, setEditingOrder] = useState<string[]>([]);
  const [editingHidden, setEditingHidden] = useState<string[]>([]);
  const baselineOrderRef = useRef<string[]>([]);
  const baselineHiddenRef = useRef<string[]>([]);
  const customizeTimerRef = useRef<number | null>(null);

  const resizingRef = useRef(false);
  const resizeStartXRef = useRef(0);
  const resizeStartWidthRef = useRef(0);

  const initialThemeMode = getCurrentTheme().mode;
  const [themeMode, setThemeMode] = useState<"light" | "dark">(initialThemeMode);
  const currentTheme = getCurrentTheme();
  const [dashboardEditing, setDashboardEditing] = useState(false);
  const [dashboardReady, setDashboardReady] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement | null>(null);
  const adminMenuRef = useRef<HTMLLIElement | null>(null);
  const [adminMenuOpen, setAdminMenuOpen] = useState(false);
  const adminMenuCloseTimer = useRef<number | null>(null);
  const [activeAdminSubmenu, setActiveAdminSubmenu] = useState<string | null>(null);
  const adminSubmenuCloseTimer = useRef<number | null>(null);
  const dragSidebarIdRef = useRef<string | null>(null);
  const dragSidebarSourceRef = useRef<"visible" | "hidden" | null>(null);
  const failedBrandAssetsRef = useRef<Set<string>>(new Set());
  const shouldHidePinButton = customizing;

  const openAdminMenu = useCallback(() => {
    if (adminMenuCloseTimer.current !== null) {
      window.clearTimeout(adminMenuCloseTimer.current);
      adminMenuCloseTimer.current = null;
    }
    if (adminSubmenuCloseTimer.current !== null) {
      window.clearTimeout(adminSubmenuCloseTimer.current);
      adminSubmenuCloseTimer.current = null;
    }
    setAdminMenuOpen(true);
  }, []);

  const scheduleAdminMenuClose = useCallback(() => {
    if (adminMenuCloseTimer.current !== null) {
      window.clearTimeout(adminMenuCloseTimer.current);
    }
    adminMenuCloseTimer.current = window.setTimeout(() => {
      setAdminMenuOpen(false);
      setActiveAdminSubmenu(null);
      adminMenuCloseTimer.current = null;
    }, 120);
  }, []);

  const openAdminSubmenu = useCallback((id: string) => {
    if (adminSubmenuCloseTimer.current !== null) {
      window.clearTimeout(adminSubmenuCloseTimer.current);
      adminSubmenuCloseTimer.current = null;
    }
    setActiveAdminSubmenu(id);
  }, []);

  const scheduleAdminSubmenuClose = useCallback(() => {
    if (adminSubmenuCloseTimer.current !== null) {
      window.clearTimeout(adminSubmenuCloseTimer.current);
    }
    adminSubmenuCloseTimer.current = window.setTimeout(() => {
      setActiveAdminSubmenu(null);
      adminSubmenuCloseTimer.current = null;
    }, 120);
  }, []);

  const handleOverflowBlur = useCallback(
    (event: ReactFocusEvent<HTMLElement>) => {
      if (customizing) return;
      const next = event.relatedTarget as Node | null;
      if (next && overflowContainerRef.current && overflowContainerRef.current.contains(next)) {
        return;
      }
      setSidebarOverflowOpen(false);
    },
    [customizing]
  );

  const updateSidebarState = useCallback(
    (updater: (prev: SidebarPrefs) => SidebarPrefs, options?: { silent?: boolean }) => {
      setSidebarPrefs((prev) => {
        const next = updater(prev);
        if (
          prev.collapsed === next.collapsed &&
          prev.width === next.width &&
          prev.pinned === next.pinned &&
          arraysEqual(prev.order, next.order) &&
          arraysEqual(prev.hidden, next.hidden)
        ) {
          sidebarPrefsRef.current = prev;
          return prev;
        }
        sidebarPrefsRef.current = next;
        if (!options?.silent) {
          setSidebarNotice(null);
        }
        return next;
      });
    },
    []
  );

  useEffect(() => {
    const off = onThemePrefsChange((prefs) => {
      if (customizingRef.current) return;
      updateSidebarState(() => normalizeSidebarPrefs(prefs.sidebar));
      if (typeof document !== "undefined") {
        setThemeMode(document.documentElement.getAttribute("data-mode") === "dark" ? "dark" : "light");
      }
    });
    const offSettings = onThemeSettingsChange((settings) => {
      const snapshot = computeBrandSnapshot(settings);
      setBrand((prev) => (brandSnapshotsEqual(prev, snapshot) ? prev : snapshot));
      setSidebarDefaultOrder(extractSidebarDefaultOrder(settings));
      if (typeof document !== "undefined") {
        setThemeMode(document.documentElement.getAttribute("data-mode") === "dark" ? "dark" : "light");
      }
    });
    const offManifest = onThemeManifestChange((next) => {
      setManifest(next);
    });
    return () => {
      off();
      offSettings();
      offManifest();
    };
  }, [updateSidebarState]);

  useEffect(() => () => {
    if (adminMenuCloseTimer.current !== null) {
      window.clearTimeout(adminMenuCloseTimer.current);
      adminMenuCloseTimer.current = null;
    }
    if (adminSubmenuCloseTimer.current !== null) {
      window.clearTimeout(adminSubmenuCloseTimer.current);
      adminSubmenuCloseTimer.current = null;
    }
  }, []);

  useEffect(() => {
    if (!adminMenuOpen) {
      setActiveAdminSubmenu(null);
    }
  }, [adminMenuOpen]);

  const loadUiSettings = useCallback(async () => {
    if (!authed) {
      const snapshot = computeBrandSnapshot(DEFAULT_THEME_SETTINGS);
      setBrand((prev) => (brandSnapshotsEqual(prev, snapshot) ? prev : snapshot));
      setSidebarDefaultOrder(extractSidebarDefaultOrder(DEFAULT_THEME_SETTINGS));
      uiSettingsEtagRef.current = null;
      return;
    }

    try {
      const res = await fetch("/settings/ui", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) throw new Error(`Failed to load UI settings (HTTP ${res.status})`);
      uiSettingsEtagRef.current = res.headers.get("ETag");
      const body = await parseJson<UiSettingsResponse>(res);
      const settings = body?.config?.ui ?? null;
      if (settings) {
        const snapshot = computeBrandSnapshot(settings);
        setBrand((prev) => (brandSnapshotsEqual(prev, snapshot) ? prev : snapshot));
        setSidebarDefaultOrder(extractSidebarDefaultOrder(settings));
        updateThemeSettings(settings);
      }
    } catch {
      const snapshot = computeBrandSnapshot(DEFAULT_THEME_SETTINGS);
      setBrand((prev) => (brandSnapshotsEqual(prev, snapshot) ? prev : snapshot));
      setSidebarDefaultOrder(extractSidebarDefaultOrder(DEFAULT_THEME_SETTINGS));
      uiSettingsEtagRef.current = null;
    }
  }, [authed]);

  const loadUserPrefs = useCallback(async (options?: LoadUserPrefsOptions) => {
    const skipStateUpdate = options?.skipStateUpdate === true;
    const manageLoadingState = !skipStateUpdate;

    if (!authed) {
      if (!skipStateUpdate) {
        updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar), { silent: true });
      }
      sidebarEtagRef.current = null;
      setSidebarReadOnly(true);
      return;
    }

    const initialPinned = sidebarPrefsRef.current.pinned;
    const initialCollapsed = sidebarPrefsRef.current.collapsed;

    if (manageLoadingState) {
      setPrefsLoading(true);
      setSidebarNotice(null);
    }

    try {
      const res = await fetch("/me/prefs/ui", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });

      if (res.status === 403) {
        setSidebarReadOnly(true);
        sidebarEtagRef.current = res.headers.get("ETag");
        setSidebarNotice({ text: "You do not have permission to customize the sidebar.", tone: "error" });
        if (!skipStateUpdate) {
          updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar), { silent: true });
        }
        return;
      }

      const body = await parseJson<UserPrefsResponse>(res);
      const etag = res.headers.get("ETag") ?? body?.etag ?? null;
      sidebarEtagRef.current = etag;

      if (!res.ok || !body?.prefs) {
        setSidebarReadOnly(false);
        if (!skipStateUpdate) {
          updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar), { silent: true });
        }
        return;
      }

      const normalized = normalizeSidebarPrefs(body.prefs.sidebar);
      if (!skipStateUpdate) {
        const pinnedChanged = sidebarPrefsRef.current.pinned !== initialPinned;
        const collapsedChanged = sidebarPrefsRef.current.collapsed !== initialCollapsed;
        const nextSidebarPrefs: SidebarPrefs = {
          ...normalized,
          pinned: pinnedChanged ? sidebarPrefsRef.current.pinned : normalized.pinned,
          collapsed: collapsedChanged ? sidebarPrefsRef.current.collapsed : normalized.collapsed,
        };
        updateSidebarState(() => nextSidebarPrefs);
        const nextThemePrefs = {
          ...body.prefs,
          sidebar: {
            ...body.prefs.sidebar,
            ...nextSidebarPrefs,
          },
        } as ThemeUserPrefs;
        updateThemePrefs(nextThemePrefs);
      }
      setSidebarReadOnly(false);
    } catch {
      setSidebarReadOnly(true);
      sidebarEtagRef.current = null;
      setSidebarNotice({ text: "Failed to load sidebar preferences. Using defaults.", tone: "error" });
      if (!skipStateUpdate) {
        updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar), { silent: true });
      }
    } finally {
      if (manageLoadingState) {
        setPrefsLoading(false);
      }
    }
  }, [authed, updateSidebarState]);

  const persistSidebarPrefs = useCallback(
    async (patch: Partial<SidebarPrefs>, options?: { silentNotice?: boolean }): Promise<boolean> => {
      if (!authed || sidebarReadOnly) return false;

      if (!sidebarEtagRef.current) {
        await loadUserPrefs({ skipStateUpdate: true });
        if (!sidebarEtagRef.current) return false;
      }

      const merged = normalizeSidebarPrefs({
        ...sidebarPrefsRef.current,
        ...patch,
      });

      setSidebarSaving(true);
      if (!options?.silentNotice) {
        setSidebarNotice(null);
      }

      try {
        const res = await fetch("/me/prefs/ui", {
          method: "PUT",
          credentials: "same-origin",
          headers: baseHeaders({
            "Content-Type": "application/json",
            "If-Match": sidebarEtagRef.current ?? "",
          }),
          body: JSON.stringify({ sidebar: merged }),
        });

        const body = await parseJson<UserPrefsResponse>(res);

        if (res.status === 409) {
          const nextEtag = body?.current_etag ?? res.headers.get("ETag") ?? null;
          sidebarEtagRef.current = nextEtag;
          setSidebarNotice({
            text: "Sidebar preferences changed elsewhere. Reloaded latest values.",
            tone: "info",
          });
          await loadUserPrefs();
          return false;
        }

        if (res.status === 403) {
          setSidebarReadOnly(true);
          setSidebarNotice({ text: "You do not have permission to customize the sidebar.", tone: "error" });
          return false;
        }

        if (!res.ok) {
          throw new Error(`Save failed (HTTP ${res.status})`);
        }

        const nextEtag = res.headers.get("ETag") ?? body?.etag ?? null;
        sidebarEtagRef.current = nextEtag;

        if (body?.prefs) {
          const normalized = normalizeSidebarPrefs(body.prefs.sidebar);
          updateSidebarState(() => normalized, { silent: options?.silentNotice });
          updateThemePrefs(body.prefs);
        } else {
          updateSidebarState(() => merged, { silent: options?.silentNotice });
        }

        return true;
      } catch (err) {
        const message = err instanceof Error ? err.message : "Failed to save sidebar preferences.";
        setSidebarNotice({ text: message, tone: "error" });
        return false;
      } finally {
        setSidebarSaving(false);
      }
    },
    [authed, sidebarReadOnly, loadUserPrefs, updateSidebarState]
  );

  const persistThemeModeSettings = useCallback(
    async (mode: "light" | "dark") => {
      if (!authed) return;

      let etag = uiSettingsEtagRef.current;
      if (!etag) {
        await loadUiSettings();
        etag = uiSettingsEtagRef.current;
        if (!etag) return;
      }

      const settings = getCachedThemeSettings();
      const payload = {
        ui: {
          theme: {
            default: settings.theme.default,
            mode,
            allow_user_override: settings.theme.allow_user_override,
            force_global: settings.theme.force_global,
            overrides: settings.theme.overrides,
            designer: {
              ...settings.theme.designer,
            },
            login: {
              ...settings.theme.login,
            },
          },
        },
      };

      try {
        const res = await fetch("/settings/ui", {
          method: "PUT",
          credentials: "same-origin",
          headers: baseHeaders({
            "Content-Type": "application/json",
            "If-Match": etag ?? "",
          }),
          body: JSON.stringify(payload),
        });

        const body = await parseJson<{
          config?: { ui?: ThemeSettings };
          etag?: string | null;
          current_etag?: string | null;
        }>(res);

        if (res.status === 409) {
          uiSettingsEtagRef.current = body?.current_etag ?? res.headers.get("ETag") ?? null;
          await loadUiSettings();
          return;
        }

        if (!res.ok) {
          return;
        }

        uiSettingsEtagRef.current = res.headers.get("ETag") ?? body?.etag ?? null;
        if (body?.config?.ui) {
          updateThemeSettings(body.config.ui);
        }
      } catch {
        // ignore persistence failures; settings will reload on next bootstrap
      }
    },
    [authed, loadUiSettings]
  );

  const persistThemeMode = useCallback(
    (mode: "light" | "dark") => {
      const settings = getCachedThemeSettings();
      const allowOverride = settings.theme.allow_user_override && !settings.theme.force_global;
      if (allowOverride) {
        void persistThemeModePreference(mode, {
          authed,
          allowOverride,
          etagRef: sidebarEtagRef,
          loadUserPrefs,
          getPrefs: getCachedThemePrefs,
          updatePrefs: updateThemePrefs,
        });
      } else {
        void persistThemeModeSettings(mode);
      }
    },
    [authed, loadUserPrefs, persistThemeModeSettings]
  );

  useEffect(() => {
    locationRef.current = loc;
  }, [loc]);

  useEffect(() => {
    const off = onUnauthorized(() => {
      const current = locationRef.current;
      if (!current.pathname.startsWith("/auth/")) {
        const intended = `${current.pathname}${current.search}${current.hash}`;
        rememberIntendedPath(intended);
        markSessionExpired();
        navigate("/auth/login", { replace: true });
      }
    });

    async function bootstrap(): Promise<void> {
      setLoading(true);
      try {
        const fp = await apiGet<Fingerprint>("/health/fingerprint");
        const req = Boolean(fp?.summary?.rbac?.require_auth);
        seedThemeRequireAuth(req);
        setRequireAuth(req);

        if (req) {
          if (!hasAuthToken()) {
            setAuthed(false);
            return;
          }
          try {
            await authMe();
            setAuthed(true);
          } catch {
            setAuthed(false);
          }
        } else {
          setAuthed(true);
        }
      } finally {
        setLoading(false);
      }
    }

    void bootstrap();
    return () => off();
  }, [navigate]);

  useEffect(() => {
    void bootstrapTheme({ fetchUserPrefs: authed });
  }, [requireAuth, authed]);

  useEffect(() => {
    if (!authed) {
      setBrand(computeBrandSnapshot(DEFAULT_THEME_SETTINGS));
      setSidebarDefaultOrder(extractSidebarDefaultOrder(DEFAULT_THEME_SETTINGS));
      return;
    }
    void loadUiSettings();
  }, [authed, loadUiSettings]);

  useEffect(() => {
    if (!authed) {
      updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar));
      sidebarEtagRef.current = null;
      setSidebarReadOnly(true);
      return;
    }
    void loadUserPrefs();
  }, [authed, loadUserPrefs, updateSidebarState]);

  useEffect(() => {
    if (!menuOpen) return;
    const handlePointerDown = (event: PointerEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setMenuOpen(false);
      }
    };
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setMenuOpen(false);
    };
    document.addEventListener("pointerdown", handlePointerDown);
    document.addEventListener("keydown", handleKeyDown);
    return () => {
      document.removeEventListener("pointerdown", handlePointerDown);
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [menuOpen]);

  useEffect(() => {
    if (!adminMenuOpen) return;
    const close = (event: PointerEvent) => {
      if (adminMenuRef.current && !adminMenuRef.current.contains(event.target as Node)) {
        setAdminMenuOpen(false);
      }
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setAdminMenuOpen(false);
      }
    };
    document.addEventListener("pointerdown", close);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", close);
      document.removeEventListener("keydown", onKey);
    };
  }, [adminMenuOpen]);

  useEffect(() => {
    if (sidebarFadeTimer.current !== null) {
      window.clearTimeout(sidebarFadeTimer.current);
      sidebarFadeTimer.current = null;
    }
    if (sidebarHideTimer.current !== null) {
      window.clearTimeout(sidebarHideTimer.current);
      sidebarHideTimer.current = null;
    }

    if (sidebarNotice?.ephemeral) {
      setSidebarToastFading(false);
      sidebarFadeTimer.current = window.setTimeout(() => {
        setSidebarToastFading(true);
      }, 2400);

      sidebarHideTimer.current = window.setTimeout(() => {
        let removed = false;
        setSidebarNotice((current) => {
          if (current === sidebarNotice) {
            removed = true;
            return null;
          }
          return current;
        });
        if (removed) {
          setSidebarToastFading(false);
        }
      }, 3200);
    } else if (!sidebarNotice) {
      setSidebarToastFading(false);
    }

    return () => {
      if (sidebarFadeTimer.current !== null) {
        window.clearTimeout(sidebarFadeTimer.current);
        sidebarFadeTimer.current = null;
      }
      if (sidebarHideTimer.current !== null) {
        window.clearTimeout(sidebarHideTimer.current);
        sidebarHideTimer.current = null;
      }
    };
  }, [sidebarNotice]);

  useEffect(() => {
    if (typeof document === "undefined") return;
    const segments = loc.pathname.split("/").filter(Boolean);
    const key = segments[0] ?? "dashboard";
    const module = MODULE_LOOKUP.get(key) ?? MODULE_LOOKUP.get("dashboard");
    const moduleLabel = module?.label ?? "Dashboard";
    const brandTitle = brand.title || DEFAULT_THEME_SETTINGS.brand.title_text;
    document.title = `${brandTitle} — ${moduleLabel}`;
  }, [brand.title, loc.pathname]);

  const setSidebarCollapsed = useCallback(
    (collapsed: boolean, options?: { persist?: boolean; silent?: boolean }) => {
      updateSidebarState(
        (prev) => (prev.collapsed === collapsed ? prev : { ...prev, collapsed }),
        { silent: options?.silent }
      );
      const shouldPersist =
        options?.persist !== undefined ? options.persist : sidebarPrefsRef.current.pinned;
      if (!sidebarReadOnly && shouldPersist) {
        void persistSidebarPrefs(
          { collapsed },
          options?.silent ? { silentNotice: true } : undefined
        );
      }
    },
    [persistSidebarPrefs, sidebarReadOnly, updateSidebarState]
  );

  const toggleSidebar = useCallback(() => {
    const nextCollapsed = !sidebarPrefsRef.current.collapsed;
    setSidebarCollapsed(nextCollapsed, { silent: true });
  }, [setSidebarCollapsed]);

  const handleSidebarToggleHover = useCallback(() => {
    setSidebarToggleHovering(true);
    if (sidebarPrefsRef.current.pinned) return;
    if (sidebarPrefsRef.current.collapsed) {
      setSidebarCollapsed(false, { persist: false, silent: true });
    }
  }, [setSidebarCollapsed, setSidebarToggleHovering]);

  const handleSidebarToggleLeave = useCallback(
    (event: ReactMouseEvent<HTMLElement>) => {
      setSidebarToggleHovering(false);
      if (customizingRef.current) return;
      if (sidebarPrefsRef.current.pinned) return;
      const nextTarget = event.relatedTarget as Node | null;
      if (nextTarget) {
        if (sidebarRef.current?.contains(nextTarget)) {
          return;
        }
        if (sidebarHoverZoneRef.current?.contains(nextTarget)) {
          return;
        }
      }
      setSidebarCollapsed(true, { persist: false, silent: true });
    },
    [setSidebarCollapsed, setSidebarToggleHovering]
  );

  const closeFloatingSidebar = useCallback(() => {
    if (customizingRef.current) return;
    if (!sidebarPrefsRef.current.pinned && !sidebarPrefsRef.current.collapsed) {
      setSidebarCollapsed(true, { persist: false, silent: true });
    }
  }, [setSidebarCollapsed]);

  const handleSidebarBlur = useCallback(
    (event: ReactFocusEvent<HTMLElement>) => {
      if (customizingRef.current) return;
      if (sidebarPrefsRef.current.pinned) return;
      const related = event.relatedTarget as Node | null;
      if (related && sidebarRef.current && sidebarRef.current.contains(related)) {
        return;
      }
      setSidebarCollapsed(true, { persist: false, silent: true });
    },
    [setSidebarCollapsed]
  );

  const toggleSidebarPin = useCallback(() => {
    const nextPinned = !sidebarPrefsRef.current.pinned;
    updateSidebarState((prev) => ({ ...prev, pinned: nextPinned }), { silent: true });
    if (!sidebarReadOnly) {
      void persistSidebarPrefs({ pinned: nextPinned }, { silentNotice: true });
    }
    if (!nextPinned && sidebarPrefsRef.current.collapsed) {
      setSidebarCollapsed(true, { persist: false, silent: true });
    }
  }, [persistSidebarPrefs, setSidebarCollapsed, sidebarReadOnly, updateSidebarState]);

  const onResizePointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (sidebarReadOnly) return;
    resizingRef.current = true;
    resizeStartXRef.current = event.clientX;
    resizeStartWidthRef.current = sidebarPrefsRef.current.width;
    (event.target as HTMLElement).setPointerCapture(event.pointerId);
    event.preventDefault();
  };

  const onResizePointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (!resizingRef.current) return;
    const delta = event.clientX - resizeStartXRef.current;
    const viewportWidth = typeof window !== "undefined" ? window.innerWidth : undefined;
    const nextWidth = clampSidebarWidth(resizeStartWidthRef.current + delta, viewportWidth);
    updateSidebarState((prev) => {
      if (prev.width === nextWidth) return prev;
      return { ...prev, width: nextWidth };
    }, { silent: true });
  };

  const commitResizedWidth = useCallback(
    (newWidth: number) => {
      if (sidebarReadOnly) return;
      if (newWidth === resizeStartWidthRef.current) return;
      void persistSidebarPrefs({ width: newWidth });
    },
    [sidebarReadOnly, persistSidebarPrefs]
  );

  const onResizePointerUp = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (!resizingRef.current) return;
    resizingRef.current = false;
    (event.target as HTMLElement).releasePointerCapture(event.pointerId);
    commitResizedWidth(sidebarPrefsRef.current.width);
  };

  const onResizePointerCancel = () => {
    if (!resizingRef.current) return;
    resizingRef.current = false;
    updateSidebarState((prev) => ({ ...prev, width: resizeStartWidthRef.current }), { silent: true });
  };

  const startCustomize = useCallback(() => {
    if (sidebarReadOnly) return;
    const order = mergeSidebarOrder(
      SIDEBAR_MODULES,
      sidebarDefaultOrder,
      sidebarPrefsRef.current.order
    );
    const hidden = sidebarPrefsRef.current.hidden ?? [];
    const hiddenSet = new Set(hidden);
    const visibleOrder = order.filter((id) => !hiddenSet.has(id));
    baselineOrderRef.current = [...visibleOrder];
    baselineHiddenRef.current = [...hidden];
    setEditingOrder(visibleOrder);
    setEditingHidden([...hidden]);
    setCustomizing(true);
  }, [sidebarReadOnly, sidebarDefaultOrder]);

  const stopCustomizeTimer = () => {
    if (customizeTimerRef.current !== null) {
      window.clearTimeout(customizeTimerRef.current);
      customizeTimerRef.current = null;
    }
  };

  const onCustomizePressStart = () => {
    if (customizing || sidebarReadOnly) return;
    stopCustomizeTimer();
    customizeTimerRef.current = window.setTimeout(() => {
      startCustomize();
      customizeTimerRef.current = null;
    }, LONG_PRESS_DURATION_MS);
  };

  const onCustomizePressEnd = () => {
    stopCustomizeTimer();
  };

  const exitCustomize = useCallback(
    (force = false) => {
      const dirty =
        !arraysEqual(editingOrder, baselineOrderRef.current) ||
        !arraysEqual(editingHidden, baselineHiddenRef.current);
      if (!force && dirty) {
        const confirmExit = window.confirm("Discard unsaved sidebar changes?");
        if (!confirmExit) return;
      }
      setEditingOrder([]);
      setEditingHidden([]);
      setCustomizing(false);
      stopCustomizeTimer();
    },
    [editingOrder, editingHidden]
  );

  const handleDefaultOrder = () => {
    const defaults = mergeSidebarOrder(SIDEBAR_MODULES, sidebarDefaultOrder, []);
    setEditingOrder(defaults);
    setEditingHidden([]);
  };

  const handleSaveCustomize = async () => {
    if (sidebarReadOnly || sidebarSaving) return;
    if (
      arraysEqual(editingOrder, baselineOrderRef.current) &&
      arraysEqual(editingHidden, baselineHiddenRef.current)
    ) {
      exitCustomize(true);
      return;
    }
    const saved = await persistSidebarPrefs({ order: editingOrder, hidden: editingHidden });
    if (saved) {
      baselineOrderRef.current = [...editingOrder];
      baselineHiddenRef.current = [...editingHidden];
      const nextOrder = [...editingOrder];
      const nextHidden = [...editingHidden];
      updateSidebarState((prev) => ({ ...prev, order: nextOrder, hidden: nextHidden }));
      exitCustomize(true);
    }
  };

  const moveSidebarModule = (moduleId: string, delta: -1 | 1) => {
    setEditingOrder((prev) => {
      const index = prev.indexOf(moduleId);
      if (index === -1) return prev;
      const nextIndex = index + delta;
      if (nextIndex < 0 || nextIndex >= prev.length) return prev;
      const next = [...prev];
      const [target] = next.splice(index, 1);
      next.splice(nextIndex, 0, target);
      return next;
    });
  };

  const moveModuleToHidden = (moduleId: string) => {
    setEditingOrder((prev) => prev.filter((id) => id !== moduleId));
    setEditingHidden((prev) => {
      if (prev.includes(moduleId)) return prev;
      return [...prev, moduleId];
    });
  };

  const moveHiddenModule = (moduleId: string, delta: -1 | 1) => {
    setEditingHidden((prev) => {
      const index = prev.indexOf(moduleId);
      if (index === -1) return prev;
      const nextIndex = index + delta;
      if (nextIndex < 0 || nextIndex >= prev.length) return prev;
      const next = [...prev];
      const [item] = next.splice(index, 1);
      next.splice(nextIndex, 0, item);
      return next;
    });
  };

  const moveModuleToVisible = (moduleId: string) => {
    setEditingHidden((prev) => prev.filter((id) => id !== moduleId));
    setEditingOrder((prev) => {
      if (prev.includes(moduleId)) return prev;
      return [...prev, moduleId];
    });
  };

  const handleSidebarDragStart = (moduleId: string, source: "visible" | "hidden") => {
    dragSidebarIdRef.current = moduleId;
    dragSidebarSourceRef.current = source;
  };

  const handleSidebarDragEnter = (targetId: string, targetCollection: "visible" | "hidden") => {
    const draggedId = dragSidebarIdRef.current;
    const sourceCollection = dragSidebarSourceRef.current;
    if (!draggedId || !sourceCollection) return;
    if (targetCollection === sourceCollection && draggedId === targetId) return;

    let nextVisible = editingOrder;
    let nextHidden = editingHidden;

    if (sourceCollection === "visible") {
      if (!nextVisible.includes(draggedId)) return;
      nextVisible = nextVisible.filter((id) => id !== draggedId);
    } else {
      if (!nextHidden.includes(draggedId)) return;
      nextHidden = nextHidden.filter((id) => id !== draggedId);
    }

    if (targetCollection === "visible") {
      let insertionIndex =
        targetId === VISIBLE_DRAG_END_ID ? nextVisible.length : nextVisible.indexOf(targetId);
      if (insertionIndex < 0) insertionIndex = nextVisible.length;
      const updatedVisible = [...nextVisible];
      updatedVisible.splice(insertionIndex, 0, draggedId);
      if (!arraysEqual(updatedVisible, editingOrder)) {
        setEditingOrder(updatedVisible);
      }
      if (!arraysEqual(nextHidden, editingHidden)) {
        setEditingHidden(nextHidden);
      }
    } else {
      let insertionIndex =
        targetId === HIDDEN_DRAG_END_ID ? nextHidden.length : nextHidden.indexOf(targetId);
      if (insertionIndex < 0) insertionIndex = nextHidden.length;
      const updatedHidden = [...nextHidden];
      updatedHidden.splice(insertionIndex, 0, draggedId);
      if (!arraysEqual(nextVisible, editingOrder)) {
        setEditingOrder(nextVisible);
      }
      if (!arraysEqual(updatedHidden, editingHidden)) {
        setEditingHidden(updatedHidden);
      }
    }

    dragSidebarSourceRef.current = targetCollection;
  };

  const handleSidebarDragEnd = () => {
    dragSidebarIdRef.current = null;
    dragSidebarSourceRef.current = null;
  };

  const handleLogout = useCallback(async () => {
    setMenuOpen(false);
    await authLogout();
    markSessionExpired();
    navigate("/auth/login", { replace: true });
  }, [navigate]);

  const handleLock = useCallback(() => {
    setMenuOpen(false);
    const intended = `${loc.pathname}${loc.search}${loc.hash}`;
    rememberIntendedPath(intended);
    markSessionExpired();
    navigate("/auth/login", { replace: true });
  }, [loc.pathname, loc.search, loc.hash, navigate]);

  const handleProfile = useCallback(() => {
    setMenuOpen(false);
    navigate("/profile/theme");
  }, [navigate]);
  const handleThemeToggle = useCallback(() => {
    const next = toggleThemeMode();
    setThemeMode(next);
    setSidebarToggleHovering(false);
    persistThemeMode(next);
    closeFloatingSidebar();
  }, [closeFloatingSidebar, persistThemeMode]);

  const handleDashboardEditToggle = useCallback(() => {
    window.dispatchEvent(new CustomEvent(DASHBOARD_TOGGLE_EDIT_MODE_EVENT));
  }, []);

  const effectiveSidebarOrder = useMemo(
    () => mergeSidebarOrder(SIDEBAR_MODULES, sidebarDefaultOrder, sidebarPrefs.order),
    [sidebarDefaultOrder, sidebarPrefs.order]
  );

  const canToggleTheme = useMemo(() => {
    const entry = [...manifest.themes, ...manifest.packs].find((item) => item.slug === currentTheme.slug);
    if (!entry?.supports?.mode) return false;
    const supported = entry.supports.mode.filter((mode): mode is "light" | "dark" => mode === "light" || mode === "dark");
    return supported.length > 1;
  }, [manifest, currentTheme.slug]);

  const hideNav = loc.pathname.startsWith("/auth/");
  const isDashboardRoute = loc.pathname.startsWith("/dashboard");

  useEffect(() => {
    const handler = (event: Event) => {
      const custom = event as CustomEvent<{ editing?: boolean; ready?: boolean }>;
      const detail = custom.detail ?? {};
      setDashboardEditing(Boolean(detail.editing));
      setDashboardReady(Boolean(detail.ready));
    };
    window.addEventListener(DASHBOARD_EDIT_MODE_STATE_EVENT, handler);
    return () => {
      window.removeEventListener(DASHBOARD_EDIT_MODE_STATE_EVENT, handler);
    };
  }, []);

  useEffect(() => {
    if (!isDashboardRoute) {
      setDashboardEditing(false);
      setDashboardReady(false);
    }
  }, [isDashboardRoute]);

  useEffect(() => {
    if (typeof document === "undefined") return;
    if (hideNav) {
      document.documentElement.style.removeProperty("--app-navbar-height");
      return;
    }

    const updateNavbarHeight = () => {
      const header = sidebarHoverZoneRef.current;
      if (!header) return;
      const nav = header.querySelector("nav");
      const height = nav instanceof HTMLElement ? nav.offsetHeight : header.offsetHeight;
      document.documentElement.style.setProperty("--app-navbar-height", `${height}px`);
    };

    updateNavbarHeight();

    if (typeof window === "undefined") {
      return;
    }

    window.addEventListener("resize", updateNavbarHeight);
    return () => {
      window.removeEventListener("resize", updateNavbarHeight);
      document.documentElement.style.removeProperty("--app-navbar-height");
    };
  }, [hideNav, themeMode, brand.headerLogoId, brand.primaryLogoId, brand.title]);

  if (loading) return null;

  if (requireAuth && !authed && !loc.pathname.startsWith("/auth/")) {
    const intended = `${loc.pathname}${loc.search}${loc.hash}`;
    rememberIntendedPath(intended);
    markSessionExpired();
    navigate("/auth/login", { replace: true });
    return null;
  }

  const hiddenForVisible = new Set(sidebarPrefs.hidden);
  const visibleSidebarOrder = effectiveSidebarOrder.filter((id) => !hiddenForVisible.has(id));
  const displayedOrder = customizing ? editingOrder : visibleSidebarOrder;

  const sidebarItems = displayedOrder
    .map((id) => MODULE_LOOKUP.get(id))
    .filter((module): module is ModuleMeta => Boolean(module));

  const primaryModules = sidebarItems.slice(0, MAX_PRIMARY_MODULES);
  const overflowModules = sidebarItems.slice(MAX_PRIMARY_MODULES);
  const overflowColumns = chunk(overflowModules, OVERFLOW_CHUNK_SIZE);
  const hiddenModuleIds = customizing ? editingHidden : sidebarPrefs.hidden;
  const hiddenModuleItems = hiddenModuleIds
    .map((id) => MODULE_LOOKUP.get(id))
    .filter((module): module is ModuleMeta => Boolean(module));

  const coreNavItems = NAVBAR_MODULES;
  const sidebarWidth = Math.max(sidebarPrefs.width, MIN_SIDEBAR_WIDTH);
  const logoSrc = resolveLogoSrc(brand, failedBrandAssetsRef.current);

  const customizingDirty =
    !arraysEqual(editingOrder, baselineOrderRef.current) ||
    !arraysEqual(editingHidden, baselineHiddenRef.current);

  const sidebarPinned = sidebarPrefs.pinned;
  const sidebarCollapsed = sidebarPrefs.collapsed;
  const navbarBackgroundClass = themeMode === "dark" ? "bg-dark" : "bg-primary";
  const headerButtonVariant = "btn-outline-light";
  const dropdownMenuTone = "dropdown-menu dropdown-menu-dark";

  const accountDropdownStyle: CSSProperties = {
    right: 0,
    left: "auto",
    inset: "calc(100% + 0.35rem) 0 auto auto",
    display: menuOpen ? "block" : "none",
  };

  const sidebarScrollClassName = [
    "flex-grow-1 overflow-auto",
    shouldHidePinButton ? "" : "sidebar-scroll-region",
  ]
    .filter(Boolean)
    .join(" ");

  const sidebarContent = (
    <div className="d-flex flex-column h-100 position-relative">
      {customizing && (
        <div className="px-3 py-2 border-bottom d-flex justify-content-end gap-2">
          <button
            type="button"
            className="btn btn-primary btn-icon btn-sm"
            onClick={() => void handleSaveCustomize()}
            disabled={!customizingDirty || sidebarSaving}
          >
            {sidebarSaving ? (
              <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            ) : (
              <i className="bi bi-floppy" aria-hidden="true"></i>
            )}
            <span className="visually-hidden">Save sidebar layout</span>
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-icon btn-sm"
            onClick={handleDefaultOrder}
          >
            <i className="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            <span className="visually-hidden">Restore default order</span>
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-icon btn-sm"
            onClick={() => exitCustomize()}
          >
            <i className="bi bi-x" aria-hidden="true"></i>
            <span className="visually-hidden">Exit customization</span>
          </button>
        </div>
      )}

      {sidebarNotice && (
        <div
          className={`shadow sidebar-notice ${sidebarNotice.tone === "error" ? "sidebar-notice--error" : "sidebar-notice--info"}`}
          role="status"
          style={{
            position: "absolute",
            top: "0.75rem",
            right: "0.75rem",
            minWidth: "14rem",
            pointerEvents: "none",
            transition: "opacity 200ms ease-in-out",
            opacity: sidebarToastFading ? 0 : 0.95,
          }}
        >
          {sidebarNotice.text}
        </div>
      )}

      <div className={sidebarScrollClassName}>
        {customizing ? (
          <div className="px-3 py-3 d-flex flex-column gap-3">
            <section>
              <h3 className="h6 text-uppercase text-muted mb-2">Sidebar Modules</h3>
              <div
                className="border rounded-2 overflow-hidden"
                onDragOver={(event) => event.preventDefault()}
                onDragEnter={(event) => {
                  const target = event.target as HTMLElement;
                  if (
                    event.currentTarget === target ||
                    target === event.currentTarget.firstElementChild
                  ) {
                    handleSidebarDragEnter(VISIBLE_DRAG_END_ID, "visible");
                  }
                }}
                onDrop={(event) => {
                  event.preventDefault();
                  handleSidebarDragEnd();
                }}
              >
                <ul className="list-group list-group-flush">
                  {primaryModules.length === 0 ? (
                    <li className="list-group-item text-muted text-center py-3">
                      No modules available.
                    </li>
                  ) : (
                    primaryModules.map((module) => {
                      const index = editingOrder.indexOf(module.id);
                      return (
                        <li
                          key={module.id}
                          className="list-group-item d-flex align-items-center justify-content-between gap-3"
                          draggable
                          onDragStart={() => handleSidebarDragStart(module.id, "visible")}
                          onDragEnter={() => handleSidebarDragEnter(module.id, "visible")}
                          onDragOver={(event) => event.preventDefault()}
                          onDragEnd={handleSidebarDragEnd}
                          onDrop={(event) => {
                            event.preventDefault();
                            handleSidebarDragEnd();
                          }}
                        >
                          <span>{module.label}</span>
                          <span aria-hidden="true" style={{ cursor: "grab", fontSize: "1.1rem" }}>
                            ⋮⋮
                          </span>
                          <div className="visually-hidden">
                            <button
                              type="button"
                              onClick={() => moveSidebarModule(module.id, -1)}
                              disabled={index <= 0}
                            >
                              Move {module.label} up
                            </button>
                            <button
                              type="button"
                              onClick={() => moveSidebarModule(module.id, 1)}
                              disabled={index === editingOrder.length - 1}
                            >
                              Move {module.label} down
                            </button>
                            <button type="button" onClick={() => moveModuleToHidden(module.id)}>
                              Hide {module.label}
                            </button>
                          </div>
                        </li>
                      );
                    })
                  )}
                </ul>
              </div>
            </section>

            {overflowModules.length > 0 && (
              <section>
                <h3 className="h6 text-uppercase text-muted mb-2">More Modules</h3>
                <div
                  className="border rounded-2 overflow-hidden"
                  onDragOver={(event) => event.preventDefault()}
                  onDragEnter={(event) => {
                    const target = event.target as HTMLElement;
                    if (
                      event.currentTarget === target ||
                      target === event.currentTarget.firstElementChild
                    ) {
                      handleSidebarDragEnter(VISIBLE_DRAG_END_ID, "visible");
                    }
                  }}
                  onDrop={(event) => {
                    event.preventDefault();
                    handleSidebarDragEnd();
                  }}
                >
                  <ul className="list-group list-group-flush">
                    {overflowModules.map((module) => {
                      const index = editingOrder.indexOf(module.id);
                      return (
                        <li
                          key={module.id}
                          className="list-group-item d-flex align-items-center justify-content-between gap-3"
                          draggable
                          onDragStart={() => handleSidebarDragStart(module.id, "visible")}
                          onDragEnter={() => handleSidebarDragEnter(module.id, "visible")}
                          onDragOver={(event) => event.preventDefault()}
                          onDragEnd={handleSidebarDragEnd}
                          onDrop={(event) => {
                            event.preventDefault();
                            handleSidebarDragEnd();
                          }}
                        >
                          <span>{module.label}</span>
                          <span aria-hidden="true" style={{ cursor: "grab", fontSize: "1.1rem" }}>
                            ⋮⋮
                          </span>
                          <div className="visually-hidden">
                            <button
                              type="button"
                              onClick={() => moveSidebarModule(module.id, -1)}
                              disabled={index <= 0}
                            >
                              Move {module.label} up
                            </button>
                            <button
                              type="button"
                              onClick={() => moveSidebarModule(module.id, 1)}
                              disabled={index === editingOrder.length - 1}
                            >
                              Move {module.label} down
                            </button>
                            <button type="button" onClick={() => moveModuleToHidden(module.id)}>
                              Hide {module.label}
                            </button>
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              </section>
            )}

            <section>
              <h3 className="h6 text-uppercase text-muted mb-2">Hidden Modules</h3>
              <div
                className="border rounded-2 overflow-hidden"
                style={{ borderStyle: "dashed" }}
                onDragOver={(event) => event.preventDefault()}
                onDragEnter={(event) => {
                  const target = event.target as HTMLElement;
                  if (
                    event.currentTarget === target ||
                    target === event.currentTarget.firstElementChild
                  ) {
                    handleSidebarDragEnter(HIDDEN_DRAG_END_ID, "hidden");
                  }
                }}
                onDrop={(event) => {
                  event.preventDefault();
                  handleSidebarDragEnd();
                }}
              >
                <ul className="list-group list-group-flush">
                  {hiddenModuleItems.length === 0 ? (
                    <li className="list-group-item text-muted text-center py-3">
                      Drag modules here to hide them.
                    </li>
                  ) : (
                    hiddenModuleItems.map((module) => {
                      const index = editingHidden.indexOf(module.id);
                      return (
                        <li
                          key={module.id}
                          className="list-group-item d-flex align-items-center justify-content-between gap-3"
                          draggable
                          onDragStart={() => handleSidebarDragStart(module.id, "hidden")}
                          onDragEnter={() => handleSidebarDragEnter(module.id, "hidden")}
                          onDragOver={(event) => event.preventDefault()}
                          onDragEnd={handleSidebarDragEnd}
                          onDrop={(event) => {
                            event.preventDefault();
                            handleSidebarDragEnd();
                          }}
                        >
                          <span>{module.label}</span>
                          <span aria-hidden="true" style={{ cursor: "grab", fontSize: "1.1rem" }}>
                            ⋮⋮
                          </span>
                          <div className="visually-hidden">
                            <button
                              type="button"
                              onClick={() => moveHiddenModule(module.id, -1)}
                              disabled={index <= 0}
                            >
                              Move {module.label} up
                            </button>
                            <button
                              type="button"
                              onClick={() => moveHiddenModule(module.id, 1)}
                              disabled={index === editingHidden.length - 1}
                            >
                              Move {module.label} down
                            </button>
                            <button type="button" onClick={() => moveModuleToVisible(module.id)}>
                              Show {module.label}
                            </button>
                          </div>
                        </li>
                      );
                    })
                  )}
                </ul>
              </div>
            </section>
          </div>
        ) : (
          <nav className="nav flex-column gap-1 px-2 py-3" aria-label="Sidebar modules">
            {primaryModules.map((module) => (
              <NavLink
                key={module.id}
                to={module.path}
                className={({ isActive }) =>
                  `nav-link text-truncate${isActive ? " active fw-semibold" : ""}`
                }
                onClick={() => {
                  closeFloatingSidebar();
                  setSidebarOverflowOpen(false);
                }}
              >
                {module.label}
              </NavLink>
            ))}
            {overflowModules.length > 0 && (
              <div
                className="position-relative"
                ref={overflowContainerRef}
                onMouseEnter={() => {
                  if (!customizing) setSidebarOverflowOpen(true);
                }}
                onMouseLeave={() => {
                  if (!customizing) setSidebarOverflowOpen(false);
                }}
                onFocusCapture={() => {
                  if (!customizing) setSidebarOverflowOpen(true);
                }}
                onBlur={handleOverflowBlur}
              >
                <button
                  type="button"
                  ref={overflowTriggerRef}
                  className="nav-link text-truncate w-100 text-start"
                  style={{ border: 0, background: "none" }}
                  aria-haspopup="true"
                  aria-expanded={customizing || sidebarOverflowOpen}
                >
                  More
                </button>
                <div
                  ref={overflowMenuRef}
                  className="mt-1"
                  style={{
                    position: "absolute",
                    top: 0,
                    left: "100%",
                    marginLeft: "0.5rem",
                    minWidth: "12rem",
                    maxHeight: "calc(100vh - 4rem)",
                    overflowY: "auto",
                    padding: "0.5rem",
                    borderRadius: "0.5rem",
                    border: "1px solid var(--bs-border-color-translucent)",
                    backgroundColor: "var(--bs-body-bg)",
                    boxShadow: "0 0.75rem 1.5rem rgba(0, 0, 0, 0.18)",
                    opacity: customizing || sidebarOverflowOpen ? 1 : 0,
                    transform: customizing || sidebarOverflowOpen ? "translateX(0)" : "translateX(-8px)",
                    pointerEvents: customizing || sidebarOverflowOpen ? "auto" : "none",
                    transition: "transform 150ms ease, opacity 150ms ease",
                    zIndex: 1040,
                  }}
                  onMouseEnter={() => {
                    if (!customizing) setSidebarOverflowOpen(true);
                  }}
                  onMouseLeave={() => {
                    if (!customizing) setSidebarOverflowOpen(false);
                  }}
                >
                  <div className="d-flex gap-3">
                    {overflowColumns.map((column, columnIndex) => (
                      <ul key={`overflow-col-${columnIndex}`} className="nav flex-column gap-1">
                        {column.map((module) => (
                          <li key={module.id}>
                            <NavLink
                              to={module.path}
                              className={({ isActive }) =>
                                `nav-link text-truncate${isActive ? " active fw-semibold" : ""}`
                              }
                              onClick={() => {
                                closeFloatingSidebar();
                                setSidebarOverflowOpen(false);
                              }}
                            >
                              {module.label}
                            </NavLink>
                          </li>
                        ))}
                      </ul>
                    ))}
                  </div>
                </div>
              </div>
            )}
          </nav>
        )}
      </div>

      {!shouldHidePinButton ? (
        <div className="sidebar-pin-floating" data-pinned={sidebarPrefs.pinned ? "true" : "false"}>
          <button
            type="button"
            className="btn btn-icon sidebar-pin-floating__button"
            onClick={toggleSidebarPin}
            aria-pressed={sidebarPrefs.pinned}
            title={sidebarPrefs.pinned ? "Unpin sidebar" : "Pin sidebar"}
            disabled={prefsLoading || sidebarSaving || sidebarReadOnly}
          >
            <i
              className={`bi ${sidebarPrefs.pinned ? "bi-pin-fill" : "bi-pin"}`}
              aria-hidden="true"
            />
            <span className="visually-hidden">
              {sidebarPrefs.pinned ? "Unpin sidebar" : "Pin sidebar"}
            </span>
          </button>
        </div>
      ) : null}
    </div>
  );

  const layout = (
    <div className="app-shell d-flex flex-column min-vh-100">
      <header
        ref={sidebarHoverZoneRef}
        onMouseLeave={handleSidebarToggleLeave}
        style={{ position: "sticky", top: 0, zIndex: 1050, backgroundColor: "var(--bs-body-bg)" }}
      >
        <nav
          className={`navbar navbar-expand-lg ${navbarBackgroundClass} border-bottom shadow-sm`}
          data-bs-theme="dark"
        >
          <div className="container-fluid">
            <div className="d-flex align-items-center gap-2">
              <button
                type="button"
                className="btn btn-icon"
                aria-label={sidebarPrefs.collapsed ? "Show sidebar" : "Hide sidebar"}
                aria-expanded={!sidebarPrefs.collapsed}
                onClick={toggleSidebar}
                onMouseEnter={handleSidebarToggleHover}
                onMouseLeave={handleSidebarToggleLeave}
                onFocus={handleSidebarToggleHover}
                disabled={prefsLoading}
              >
                <i
                  className={`bi ${
                    !sidebarPrefs.collapsed || sidebarToggleHovering
                      ? "bi-layout-text-sidebar-reverse"
                      : "bi-layout-sidebar"
                  }`}
                  aria-hidden="true"
                />
                <span className="visually-hidden">
                  {sidebarPrefs.collapsed ? "Show navigation menu" : "Hide navigation menu"}
                </span>
              </button>
              <NavLink to="/" className="navbar-brand d-flex align-items-center gap-2">
                <span
                  className="d-inline-flex align-items-center justify-content-center"
                  style={{ minHeight: "40px", padding: "4px 0" }}
                >
                  <img
                    src={logoSrc}
                    alt="phpGRC logo"
                    height={40}
                    style={{ width: "auto", maxHeight: "40px" }}
                    data-fallback-applied="false"
                    onError={(event) => {
                      if (event.currentTarget.dataset.fallbackApplied === "true") return;
                      event.currentTarget.dataset.fallbackApplied = "true";
                      if (brand.headerLogoId) {
                        failedBrandAssetsRef.current.add(brand.headerLogoId);
                      } else if (brand.primaryLogoId) {
                        failedBrandAssetsRef.current.add(brand.primaryLogoId);
                      }
                      event.currentTarget.src = DEFAULT_LOGO_SRC;
                      setBrand((prev) => ({ ...prev }));
                    }}
                  />
                </span>
              </NavLink>
            </div>
            <div className="flex-grow-1 d-flex align-items-center">
              <ul className="navbar-nav me-auto align-items-center gap-1" aria-label="Primary navigation">
                {coreNavItems.map((module) => {
                  if (module.id === "admin") {
                    const adminActive = loc.pathname.startsWith("/admin");
                    return (
                      <li
                        key={module.id}
                        className="nav-item dropdown"
                        ref={adminMenuRef}
                        onMouseEnter={openAdminMenu}
                        onMouseLeave={scheduleAdminMenuClose}
                        onFocusCapture={openAdminMenu}
                        onBlur={(event) => {
                          if (
                            adminMenuRef.current &&
                            !adminMenuRef.current.contains(event.relatedTarget as Node)
                          ) {
                            scheduleAdminMenuClose();
                          }
                        }}
                      >
                        <NavLink
                          to={module.path}
                          className={({ isActive }) =>
                            `nav-link dropdown-toggle${isActive || adminActive ? " active" : ""}`
                          }
                          role="button"
                        >
                          {module.label}
                        </NavLink>
                        <div
                          role="menu"
                          aria-hidden={adminMenuOpen ? "false" : "true"}
                          className={`${dropdownMenuTone} shadow border-0 rounded-2`}
                          style={{
                            display: adminMenuOpen ? "block" : "none",
                            marginTop: "0.5rem",
                            minWidth: "13rem",
                          }}
                          onMouseEnter={openAdminMenu}
                          onMouseLeave={scheduleAdminMenuClose}
                        >
                          {ADMIN_NAV_ITEMS.map((item) => {
                            const submenuActive =
                              item.children?.some((child) => child.to && loc.pathname.startsWith(child.to)) ?? false;
                            if (item.children && item.children.length > 0) {
                              const submenuOpen = adminMenuOpen && activeAdminSubmenu === item.id;
                              return (
                                <div
                                  key={item.id}
                                  className="position-relative"
                                  onMouseEnter={() => openAdminSubmenu(item.id)}
                                  onMouseLeave={scheduleAdminSubmenuClose}
                                  onFocusCapture={() => openAdminSubmenu(item.id)}
                                  onBlur={(event) => {
                                    if (!event.currentTarget.contains(event.relatedTarget as Node)) {
                                      scheduleAdminSubmenuClose();
                                    }
                                  }}
                                >
                                  {item.to ? (
                                    <NavLink
                                      to={item.to}
                                      className={({ isActive }) =>
                                        `dropdown-item d-flex align-items-center justify-content-between${
                                          isActive || submenuActive ? " active fw-semibold" : ""
                                        }`
                                      }
                                      role="menuitem"
                                      onClick={() => {
                                        setAdminMenuOpen(false);
                                        setActiveAdminSubmenu(null);
                                      }}
                                      onMouseEnter={() => openAdminSubmenu(item.id)}
                                    >
                                      <span>{item.label}</span>
                                    </NavLink>
                                  ) : item.href ? (
                                    <a
                                      href={item.href}
                                      className="dropdown-item d-flex align-items-center justify-content-between"
                                      role="menuitem"
                                      onClick={() => {
                                        setAdminMenuOpen(false);
                                        setActiveAdminSubmenu(null);
                                      }}
                                      onMouseEnter={() => openAdminSubmenu(item.id)}
                                    >
                                      <span>{item.label}</span>
                                    </a>
                                  ) : null}
                                  <div
                                    role="menu"
                                    aria-hidden={submenuOpen ? "false" : "true"}
                                    className={`${dropdownMenuTone} shadow border-0 rounded-2`}
                                    style={{
                                      display: submenuOpen ? "block" : "none",
                                      position: "absolute",
                                      top: "-0.25rem",
                                      left: "calc(100% - 0.25rem)",
                                      minWidth: "12rem",
                                    }}
                                    onMouseEnter={() => openAdminSubmenu(item.id)}
                                    onMouseLeave={scheduleAdminSubmenuClose}
                                  >
                                    {item.children.map((child) => {
                                      if (child.to) {
                                        return (
                                          <NavLink
                                            key={child.id}
                                            to={child.to}
                                            className={({ isActive }) =>
                                              `dropdown-item${isActive ? " active fw-semibold" : ""}`
                                            }
                                            role="menuitem"
                                            onClick={() => {
                                              setAdminMenuOpen(false);
                                              setActiveAdminSubmenu(null);
                                            }}
                                          >
                                            {child.label}
                                          </NavLink>
                                        );
                                      }
                                      if (child.href) {
                                        return (
                                          <a
                                            key={child.id}
                                            href={child.href}
                                            className="dropdown-item"
                                            role="menuitem"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                          >
                                            {child.label}
                                          </a>
                                        );
                                      }
                                      return null;
                                    })}
                                  </div>
                                </div>
                              );
                            }

                            if (item.to) {
                              return (
                                <NavLink
                                  key={item.id}
                                  to={item.to}
                                  className={({ isActive }) =>
                                    `dropdown-item${isActive ? " active fw-semibold" : ""}`
                                  }
                                  role="menuitem"
                                  onClick={() => {
                                    setAdminMenuOpen(false);
                                    setActiveAdminSubmenu(null);
                                  }}
                                  onMouseEnter={() => setActiveAdminSubmenu(null)}
                                  onFocus={() => setActiveAdminSubmenu(null)}
                                >
                                  {item.label}
                                </NavLink>
                              );
                            }

                            if (item.href) {
                              return (
                                <a
                                  key={item.id}
                                  href={item.href}
                                  className="dropdown-item"
                                  role="menuitem"
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  onMouseEnter={() => setActiveAdminSubmenu(null)}
                                  onFocus={() => setActiveAdminSubmenu(null)}
                                >
                                  {item.label}
                                </a>
                              );
                            }

                            return null;
                          })}
                        </div>
                      </li>
                    );
                  }

                  return (
                    <li className="nav-item" key={module.id}>
                      <NavLink
                        to={module.path}
                        className={({ isActive }) => `nav-link${isActive ? " active" : ""}`}
                      >
                        {module.label}
                      </NavLink>
                    </li>
                  );
                })}
              </ul>
            </div>
            <div className="d-flex align-items-center gap-2">
              {requireAuth && !authed ? (
                <NavLink className={`btn btn-sm ${headerButtonVariant}`} to="/auth/login">
                  Login
                </NavLink>
              ) : (
                <>
                  {isDashboardRoute ? (
                    <button
                      type="button"
                      className="btn btn-icon"
                      aria-label={dashboardEditing ? "Exit dashboard edit mode" : "Enter dashboard edit mode"}
                      aria-pressed={dashboardEditing}
                      title={dashboardEditing ? "Exit dashboard edit mode" : "Enter dashboard edit mode"}
                      disabled={!dashboardReady && !dashboardEditing}
                      onClick={handleDashboardEditToggle}
                    >
                      <i
                        className={`bi ${dashboardEditing ? "bi-speedometer" : "bi-speedometer2"}`}
                        aria-hidden="true"
                      />
                    </button>
                  ) : null}
                  {canToggleTheme ? (
                    <button
                      type="button"
                      className="btn btn-icon theme-toggle-btn"
                      data-theme-mode={themeMode}
                      aria-label={`Switch to ${themeMode === "dark" ? "light" : "dark"} mode`}
                      title={`Switch to ${themeMode === "dark" ? "light" : "dark"} mode`}
                      onClick={handleThemeToggle}
                    >
                      <span className="theme-toggle__icons" aria-hidden="true">
                        <i className="bi bi-brightness-high-fill theme-toggle__icon theme-toggle__icon--sun" />
                        <i className="bi bi-moon-stars-fill theme-toggle__icon theme-toggle__icon--moon" />
                      </span>
                    </button>
                  ) : null}
                  <div className="dropdown" ref={menuRef}>
                    <button
                      type="button"
                      className="btn btn-icon dropdown-toggle"
                      aria-expanded={menuOpen}
                      onClick={() => setMenuOpen((prev) => !prev)}
                    >
                      Account
                    </button>
                    <div
                      className={`${dropdownMenuTone} dropdown-menu-end${menuOpen ? " show" : ""}`}
                      style={accountDropdownStyle}
                    >
                      <button type="button" className="dropdown-item" onClick={handleProfile}>
                        Profile
                      </button>
                      <button type="button" className="dropdown-item" onClick={handleLock}>
                        Lock
                      </button>
                      <button type="button" className="dropdown-item text-danger" onClick={handleLogout}>
                        Logout
                      </button>
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        </nav>
      </header>

      <div className="app-body d-flex flex-grow-1 position-relative">
        {sidebarPinned ? (
          !sidebarCollapsed && (
            <>
              <aside
                ref={sidebarRef}
                className="border-end bg-body"
                aria-label="Secondary navigation"
                style={{
                  width: `${sidebarWidth}px`,
                  minWidth: `${MIN_SIDEBAR_WIDTH}px`,
                  position: "relative",
                }}
                tabIndex={sidebarCollapsed || sidebarReadOnly ? -1 : 0}
                onMouseLeave={handleSidebarToggleLeave}
                onPointerDown={onCustomizePressStart}
                onPointerUp={onCustomizePressEnd}
                onPointerLeave={onCustomizePressEnd}
                onPointerCancel={onCustomizePressEnd}
                onKeyDown={(event) => {
                  if (!sidebarReadOnly && !customizing && (event.key === "Enter" || event.key === " ")) {
                    event.preventDefault();
                    startCustomize();
                  }
                  if (customizing && event.key === "Escape") {
                    event.preventDefault();
                    exitCustomize(true);
                  }
                }}
              >
                {sidebarContent}
              </aside>
              <div
                role="separator"
                aria-orientation="vertical"
                className="bg-body"
                style={{ width: "4px", cursor: sidebarReadOnly ? "not-allowed" : "col-resize" }}
                onPointerDown={onResizePointerDown}
                onPointerMove={onResizePointerMove}
                onPointerUp={onResizePointerUp}
                onPointerCancel={onResizePointerCancel}
              />
            </>
          )
        ) : (
          <aside
            ref={sidebarRef}
            className="border-end bg-body shadow"
            aria-label="Secondary navigation"
            style={{
              position: "absolute",
              top: 0,
              bottom: 0,
              left: 0,
              width: `${sidebarWidth}px`,
              minWidth: `${MIN_SIDEBAR_WIDTH}px`,
              transform: sidebarCollapsed ? "translateX(-105%)" : "translateX(0)",
              transition: "transform 220ms ease, opacity 220ms ease",
              opacity: sidebarCollapsed ? 0 : 1,
              pointerEvents: sidebarCollapsed ? "none" : "auto",
              zIndex: 1040,
              backgroundColor: "var(--bs-body-bg)",
            }}
            tabIndex={sidebarCollapsed ? -1 : 0}
            aria-hidden={sidebarCollapsed}
            onMouseLeave={handleSidebarToggleLeave}
            onPointerDown={onCustomizePressStart}
            onPointerUp={onCustomizePressEnd}
            onPointerLeave={onCustomizePressEnd}
            onPointerCancel={onCustomizePressEnd}
            onKeyDown={(event) => {
              if (!sidebarReadOnly && !customizing && (event.key === "Enter" || event.key === " ")) {
                event.preventDefault();
                startCustomize();
              }
              if (customizing && event.key === "Escape") {
                event.preventDefault();
                exitCustomize(true);
              }
            }}
            onBlurCapture={handleSidebarBlur}
          >
            {sidebarContent}
          </aside>
        )}
        <main
          id="main"
          role="main"
          ref={pageFadeRef}
          className="flex-grow-1 overflow-auto page-fade-target"
          onClickCapture={closeFloatingSidebar}
          onFocusCapture={closeFloatingSidebar}
        >
          <Outlet />
        </main>
      </div>
    </div>
  );

  const content = hideNav ? (
    <main id="main" role="main" ref={pageFadeRef} className="page-fade-target">
      <Outlet />
    </main>
  ) : (
    layout
  );

  return <ToastProvider>{content}</ToastProvider>;
}
