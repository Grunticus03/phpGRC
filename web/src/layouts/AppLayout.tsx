import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
import {
  bootstrapTheme,
  getCachedThemePrefs,
  getCachedThemeSettings,
  onThemePrefsChange,
  onThemeSettingsChange,
  updateThemePrefs,
  updateThemeSettings,
} from "../theme/themeManager";
import {
  NAVBAR_MODULES,
  MODULE_LOOKUP,
  SIDEBAR_MODULES,
  type ModuleMeta,
} from "./modules";
import {
  MIN_SIDEBAR_WIDTH,
  clampSidebarWidth,
  mergeSidebarOrder,
} from "./sidebarUtils";
import {
  DEFAULT_THEME_SETTINGS,
  DEFAULT_USER_PREFS,
  type ThemeSettings,
  type ThemeUserPrefs,
} from "../routes/admin/themeData";

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

type UiSettingsResponse = { config?: { ui?: ThemeSettings } };
type UserPrefsResponse = {
  prefs?: ThemeUserPrefs;
  etag?: string | null;
  current_etag?: string | null;
  message?: string;
};

const DEFAULT_LOGO_SRC = "/api/images/phpGRC-light-horizontal-trans.png";
const LONG_PRESS_DURATION_MS = 600;

const brandAssetUrl = (assetId: string): string =>
  `/api/settings/ui/brand-assets/${encodeURIComponent(assetId)}/download`;

const ADMIN_NAV_ITEMS = [
  { id: "admin.settings", label: "Settings", to: "/admin/settings" },
  { id: "admin.roles", label: "Roles", to: "/admin/roles" },
  { id: "admin.user-roles", label: "User Roles", to: "/admin/user-roles" },
  { id: "admin.users", label: "Users", to: "/admin/users" },
  { id: "admin.audit", label: "Audit Logs", to: "/admin/audit" },
] as const;

type SidebarPrefs = {
  collapsed: boolean;
  width: number;
  order: string[];
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

const resolveLogoSrc = (brand: BrandSnapshot): string => {
  if (brand.headerLogoId) {
    return `${brandAssetUrl(brand.headerLogoId)}?v=${encodeURIComponent(brand.headerLogoId)}`;
  }
  if (brand.primaryLogoId) {
    return `${brandAssetUrl(brand.primaryLogoId)}?v=${encodeURIComponent(brand.primaryLogoId)}`;
  }
  return DEFAULT_LOGO_SRC;
};

const normalizeSidebarPrefs = (prefs?: ThemeUserPrefs["sidebar"] | SidebarPrefs): SidebarPrefs => {
  const source = prefs ?? DEFAULT_USER_PREFS.sidebar;
  const collapsed = Boolean(source.collapsed);
  const widthRaw = typeof source.width === "number" ? source.width : DEFAULT_USER_PREFS.sidebar.width;
  const width = clampSidebarWidth(widthRaw);
  const order = Array.isArray(source.order)
    ? source.order
        .map((value) => (typeof value === "string" ? value.trim() : ""))
        .filter((value) => value !== "")
    : [];
  return { collapsed, width, order };
};

export default function AppLayout(): JSX.Element | null {
  const loc = useLocation();
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [requireAuth, setRequireAuth] = useState(false);
  const [authed, setAuthed] = useState(false);

  const [brand, setBrand] = useState<BrandSnapshot>(() =>
    computeBrandSnapshot(getCachedThemeSettings())
  );
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

  const [sidebarNotice, setSidebarNotice] = useState<SidebarNotice | null>(null);
  const [sidebarSaving, setSidebarSaving] = useState<boolean>(false);
  const [sidebarReadOnly, setSidebarReadOnly] = useState<boolean>(false);
  const [prefsLoading, setPrefsLoading] = useState<boolean>(false);
  const [sidebarToastFading, setSidebarToastFading] = useState(false);
  const sidebarFadeTimer = useRef<number | null>(null);
  const sidebarHideTimer = useRef<number | null>(null);

  const [customizing, setCustomizing] = useState<boolean>(false);
  const customizingRef = useRef(false);
  useEffect(() => {
    customizingRef.current = customizing;
  }, [customizing]);

  const [editingOrder, setEditingOrder] = useState<string[]>([]);
  const baselineOrderRef = useRef<string[]>([]);
  const customizeTimerRef = useRef<number | null>(null);

  const resizingRef = useRef(false);
  const resizeStartXRef = useRef(0);
  const resizeStartWidthRef = useRef(0);

  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement | null>(null);
  const adminMenuRef = useRef<HTMLDivElement | null>(null);
  const [adminMenuOpen, setAdminMenuOpen] = useState(false);

  const updateSidebarState = useCallback((updater: (prev: SidebarPrefs) => SidebarPrefs) => {
    setSidebarPrefs((prev) => {
      const next = updater(prev);
      if (
        prev.collapsed === next.collapsed &&
        prev.width === next.width &&
        arraysEqual(prev.order, next.order)
      ) {
        sidebarPrefsRef.current = prev;
        return prev;
      }
      sidebarPrefsRef.current = next;
      return next;
    });
  }, []);

  useEffect(() => {
    const off = onThemePrefsChange((prefs) => {
      if (customizingRef.current) return;
      updateSidebarState(() => normalizeSidebarPrefs(prefs.sidebar));
    });
    const offSettings = onThemeSettingsChange((settings) => {
      setBrand(computeBrandSnapshot(settings));
      setSidebarDefaultOrder(extractSidebarDefaultOrder(settings));
    });
    return () => {
      off();
      offSettings();
    };
  }, [updateSidebarState]);

  const loadUiSettings = useCallback(async () => {
    if (!authed) {
      setBrand(computeBrandSnapshot(DEFAULT_THEME_SETTINGS));
      setSidebarDefaultOrder(extractSidebarDefaultOrder(DEFAULT_THEME_SETTINGS));
      return;
    }

    try {
      const res = await fetch("/api/settings/ui", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) throw new Error(`Failed to load UI settings (HTTP ${res.status})`);
      const body = await parseJson<UiSettingsResponse>(res);
      const settings = body?.config?.ui ?? null;
      if (settings) {
        setBrand(computeBrandSnapshot(settings));
        setSidebarDefaultOrder(extractSidebarDefaultOrder(settings));
        updateThemeSettings(settings);
      }
    } catch {
      setBrand(computeBrandSnapshot(DEFAULT_THEME_SETTINGS));
      setSidebarDefaultOrder(extractSidebarDefaultOrder(DEFAULT_THEME_SETTINGS));
    }
  }, [authed]);

  const loadUserPrefs = useCallback(async () => {
    if (!authed) {
      updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar));
      sidebarEtagRef.current = null;
      setSidebarReadOnly(true);
      return;
    }

    setPrefsLoading(true);
    setSidebarNotice(null);

    try {
      const res = await fetch("/api/me/prefs/ui", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });

      if (res.status === 403) {
        setSidebarReadOnly(true);
        sidebarEtagRef.current = res.headers.get("ETag");
        setSidebarNotice({ text: "You do not have permission to customize the sidebar.", tone: "error" });
        updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar));
        return;
      }

      const body = await parseJson<UserPrefsResponse>(res);
      const etag = res.headers.get("ETag") ?? body?.etag ?? null;
      sidebarEtagRef.current = etag;

      if (!res.ok || !body?.prefs) {
        setSidebarReadOnly(false);
        updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar));
        return;
      }

      const normalized = normalizeSidebarPrefs(body.prefs.sidebar);
      updateSidebarState(() => normalized);
      updateThemePrefs(body.prefs);
      setSidebarReadOnly(false);
    } catch {
      setSidebarReadOnly(true);
      sidebarEtagRef.current = null;
      setSidebarNotice({ text: "Failed to load sidebar preferences. Using defaults.", tone: "error" });
      updateSidebarState(() => normalizeSidebarPrefs(DEFAULT_USER_PREFS.sidebar));
    } finally {
      setPrefsLoading(false);
    }
  }, [authed, updateSidebarState]);

  const persistSidebarPrefs = useCallback(
    async (patch: Partial<SidebarPrefs>): Promise<boolean> => {
      if (!authed || sidebarReadOnly) return false;

      if (!sidebarEtagRef.current) {
        await loadUserPrefs();
        if (!sidebarEtagRef.current) return false;
      }

      const merged = normalizeSidebarPrefs({
        ...sidebarPrefsRef.current,
        ...patch,
      });

      setSidebarSaving(true);
      setSidebarNotice(null);

      try {
        const res = await fetch("/api/me/prefs/ui", {
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
          updateSidebarState(() => normalized);
          updateThemePrefs(body.prefs);
        } else {
          updateSidebarState(() => merged);
        }

        setSidebarNotice({ text: "Sidebar preferences saved.", tone: "info", ephemeral: true });
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

  useEffect(() => {
    const off = onUnauthorized(() => {
      if (!loc.pathname.startsWith("/auth/")) {
        const intended = `${loc.pathname}${loc.search}${loc.hash}`;
        rememberIntendedPath(intended);
        markSessionExpired();
        navigate("/auth/login", { replace: true });
      }
    });

    async function bootstrap(): Promise<void> {
      setLoading(true);
      try {
        const fp = await apiGet<Fingerprint>("/api/health/fingerprint");
        const req = Boolean(fp?.summary?.rbac?.require_auth);
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loc.pathname]);

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

  const toggleSidebar = useCallback(() => {
    const nextCollapsed = !sidebarPrefsRef.current.collapsed;
    updateSidebarState((prev) => ({ ...prev, collapsed: nextCollapsed }));
    if (!sidebarReadOnly) {
      void persistSidebarPrefs({ collapsed: nextCollapsed });
    }
  }, [sidebarReadOnly, persistSidebarPrefs, updateSidebarState]);

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
    });
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
    updateSidebarState((prev) => ({ ...prev, width: resizeStartWidthRef.current }));
  };

  const startCustomize = useCallback(() => {
    if (sidebarReadOnly) return;
    const order = mergeSidebarOrder(
      SIDEBAR_MODULES,
      sidebarDefaultOrder,
      sidebarPrefsRef.current.order
    );
    baselineOrderRef.current = [...order];
    setEditingOrder(order);
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
      const dirty = !arraysEqual(editingOrder, baselineOrderRef.current);
      if (!force && dirty) {
        const confirmExit = window.confirm("Discard unsaved sidebar changes?");
        if (!confirmExit) return;
      }
      setEditingOrder([]);
      setCustomizing(false);
      stopCustomizeTimer();
    },
    [editingOrder]
  );

  const handleCancelCustomize = () => {
    setEditingOrder([...baselineOrderRef.current]);
    exitCustomize(true);
  };

  const handleDefaultOrder = () => {
    const defaults = mergeSidebarOrder(SIDEBAR_MODULES, sidebarDefaultOrder, []);
    setEditingOrder(defaults);
  };

  const handleSaveCustomize = async () => {
    if (sidebarReadOnly || sidebarSaving) return;
    if (arraysEqual(editingOrder, baselineOrderRef.current)) {
      exitCustomize(true);
      return;
    }
    const saved = await persistSidebarPrefs({ order: editingOrder });
    if (saved) {
      baselineOrderRef.current = [...editingOrder];
      updateSidebarState((prev) => ({ ...prev, order: editingOrder }));
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

  const effectiveSidebarOrder = useMemo(
    () => mergeSidebarOrder(SIDEBAR_MODULES, sidebarDefaultOrder, sidebarPrefs.order),
    [sidebarDefaultOrder, sidebarPrefs.order]
  );

  if (loading) return null;

  if (requireAuth && !authed && !loc.pathname.startsWith("/auth/")) {
    const intended = `${loc.pathname}${loc.search}${loc.hash}`;
    rememberIntendedPath(intended);
    markSessionExpired();
    navigate("/auth/login", { replace: true });
    return null;
  }

  const hideNav = loc.pathname.startsWith("/auth/");
  if (hideNav) {
    return (
      <main id="main" role="main">
        <Outlet />
      </main>
    );
  }

  const displayedOrder = customizing ? editingOrder : effectiveSidebarOrder;
  const sidebarItems = displayedOrder
    .map((id) => MODULE_LOOKUP.get(id))
    .filter((module): module is ModuleMeta => Boolean(module));

  const coreNavItems = NAVBAR_MODULES;
  const sidebarWidth = Math.max(sidebarPrefs.width, MIN_SIDEBAR_WIDTH);
  const logoSrc = resolveLogoSrc(brand);

  const customizingDirty = !arraysEqual(editingOrder, baselineOrderRef.current);

  return (
    <div className="app-shell d-flex flex-column min-vh-100">
      <header className="border-bottom bg-body">
        <div className="container-fluid d-flex align-items-center gap-3 py-2">
          <button
            type="button"
            className="p-0 border-0 bg-transparent"
            aria-label={sidebarPrefs.collapsed ? "Show sidebar" : "Hide sidebar"}
            aria-expanded={!sidebarPrefs.collapsed}
            onClick={toggleSidebar}
            disabled={prefsLoading}
            style={{
              width: "2.5rem",
              height: "2.5rem",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              opacity: prefsLoading ? 0.4 : 1,
            }}
          >
            <span aria-hidden="true" style={{ fontSize: "1.5rem", lineHeight: 1 }}>
              ☰
            </span>
            <span className="visually-hidden">
              {sidebarPrefs.collapsed ? "Show navigation menu" : "Hide navigation menu"}
            </span>
          </button>
          <NavLink to="/" className="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
            <span
              className="d-inline-flex align-items-center justify-content-center"
              style={{ minHeight: "40px", padding: "4px 0" }}
            >
              <img
                src={logoSrc}
                alt={brand.title}
                height={40}
                style={{ width: "auto", maxHeight: "40px" }}
                data-fallback-applied="false"
                onError={(event) => {
                  if (event.currentTarget.dataset.fallbackApplied === "true") return;
                  event.currentTarget.dataset.fallbackApplied = "true";
                  event.currentTarget.src = DEFAULT_LOGO_SRC;
                }}
              />
            </span>
            <span className="fw-semibold d-none d-sm-inline">{brand.title}</span>
          </NavLink>
          <nav className="nav nav-pills gap-1 ms-3 flex-wrap align-items-center" aria-label="Primary navigation">
            {coreNavItems.map((module) => {
              if (module.id === "admin") {
                const adminActive = loc.pathname.startsWith("/admin");
                return (
                  <div
                    key={module.id}
                    className="position-relative d-inline-flex"
                    ref={adminMenuRef}
                    onMouseEnter={() => setAdminMenuOpen(true)}
                    onMouseLeave={() => setAdminMenuOpen(false)}
                    onFocusCapture={() => setAdminMenuOpen(true)}
                    onBlur={(event) => {
                      if (
                        adminMenuRef.current &&
                        !adminMenuRef.current.contains(event.relatedTarget as Node)
                      ) {
                        setAdminMenuOpen(false);
                      }
                    }}
                  >
                    <NavLink
                      to={module.path}
                      className={`nav-link py-1 px-2${adminActive ? " active fw-semibold" : ""}`}
                    >
                      {module.label}
                    </NavLink>
                    <div
                      role="menu"
                      aria-hidden={adminMenuOpen ? "false" : "true"}
                      className="shadow-sm border bg-body rounded-2"
                      style={{
                        position: "absolute",
                        top: "calc(100% + 0.35rem)",
                        left: 0,
                        minWidth: "13rem",
                        padding: "0.5rem 0",
                        transform: adminMenuOpen ? "translateY(0)" : "translateY(-6px)",
                        opacity: adminMenuOpen ? 1 : 0,
                        visibility: adminMenuOpen ? "visible" : "hidden",
                        transition: "opacity 180ms ease, transform 180ms ease, visibility 0s linear 90ms",
                        zIndex: 1050,
                        pointerEvents: adminMenuOpen ? "auto" : "none",
                      }}
                    >
                      {ADMIN_NAV_ITEMS.map((item) => (
                        <NavLink
                          key={item.id}
                          to={item.to}
                          className={({ isActive }) =>
                            `dropdown-item${isActive ? " active fw-semibold" : ""}`
                          }
                          role="menuitem"
                          onClick={() => setAdminMenuOpen(false)}
                        >
                          {item.label}
                        </NavLink>
                      ))}
                    </div>
                  </div>
                );
              }

              return (
                <NavLink
                  key={module.id}
                  to={module.path}
                  className={({ isActive }) =>
                    `nav-link py-1 px-2${isActive ? " active fw-semibold" : ""}`
                  }
                >
                  {module.label}
                </NavLink>
              );
            })}
          </nav>
          <div className="ms-auto d-flex align-items-center gap-2">
            {requireAuth && !authed ? (
              <NavLink className="btn btn-primary btn-sm" to="/auth/login">
                Login
              </NavLink>
            ) : (
              <div className="dropdown" ref={menuRef}>
                <button
                  type="button"
                  className="btn btn-outline-secondary btn-sm dropdown-toggle"
                  aria-expanded={menuOpen}
                  onClick={() => setMenuOpen((prev) => !prev)}
                >
                  Account
                </button>
                <div className={`dropdown-menu dropdown-menu-end${menuOpen ? " show" : ""}`}>
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
            )}
          </div>
        </div>
      </header>

      <div className="app-body d-flex flex-grow-1 position-relative">
        {!sidebarPrefs.collapsed && (
          <>
            <aside
              className="border-end bg-body"
              aria-label="Secondary navigation"
              style={{
                width: `${sidebarWidth}px`,
                minWidth: `${MIN_SIDEBAR_WIDTH}px`,
                position: "relative",
              }}
              tabIndex={sidebarReadOnly ? -1 : 0}
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
              <div className="d-flex flex-column h-100">
                <div className="px-3 py-3 border-bottom">
                  <h2 className="h6 mb-1">Modules</h2>
                  <small className="text-muted d-block">
                    {sidebarReadOnly
                      ? "Sidebar customization is disabled for your account."
                      : "Press and hold anywhere on the sidebar to customize module order."}
                  </small>
                </div>

                {customizing && (
                  <div className="px-3 py-2 border-bottom d-flex flex-wrap gap-2">
                    <button
                      type="button"
                      className="btn btn-primary btn-sm"
                      onClick={() => void handleSaveCustomize()}
                      disabled={!customizingDirty || sidebarSaving}
                    >
                      {sidebarSaving ? "Saving…" : "Save"}
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      onClick={handleCancelCustomize}
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      onClick={handleDefaultOrder}
                    >
                      Default
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      onClick={() => exitCustomize()}
                    >
                      Exit
                    </button>
                  </div>
                )}

                {sidebarNotice && (
                  <div
                    className={`shadow alert ${sidebarNotice.tone === "error" ? "alert-danger" : "alert-info"}`}
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

                <div className="flex-grow-1 overflow-auto">
                  {customizing ? (
                    <ul className="list-group list-group-flush">
                      {sidebarItems.map((module, index) => (
                        <li
                          key={module.id}
                          className="list-group-item d-flex align-items-center justify-content-between gap-2"
                        >
                          <span>{module.label}</span>
                          <div className="btn-group btn-group-sm" role="group" aria-label={`${module.label} position`}>
                            <button
                              type="button"
                              className="btn btn-outline-secondary"
                              onClick={() => moveSidebarModule(module.id, -1)}
                              disabled={index === 0}
                              aria-label={`Move ${module.label} up`}
                            >
                              ↑
                            </button>
                            <button
                              type="button"
                              className="btn btn-outline-secondary"
                              onClick={() => moveSidebarModule(module.id, 1)}
                              disabled={index === sidebarItems.length - 1}
                              aria-label={`Move ${module.label} down`}
                            >
                              ↓
                            </button>
                          </div>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <nav className="nav flex-column gap-1 px-2 py-3" aria-label="Sidebar modules">
                      {sidebarItems.map((module) => (
                        <NavLink
                          key={module.id}
                          to={module.path}
                          className={({ isActive }) =>
                            `nav-link text-truncate${isActive ? " active fw-semibold" : ""}`
                          }
                        >
                          {module.label}
                        </NavLink>
                      ))}
                    </nav>
                  )}
                </div>
              </div>
            </aside>
            <div
              role="separator"
              aria-orientation="vertical"
              className="bg-body border-end"
              style={{ width: "4px", cursor: sidebarReadOnly ? "not-allowed" : "col-resize" }}
              onPointerDown={onResizePointerDown}
              onPointerMove={onResizePointerMove}
              onPointerUp={onResizePointerUp}
              onPointerCancel={onResizePointerCancel}
            />
          </>
        )}
        <main id="main" role="main" className="flex-grow-1 overflow-auto">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
