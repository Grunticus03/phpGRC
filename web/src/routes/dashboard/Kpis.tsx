import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
  Chart as ChartJS,
  ArcElement,
  BarElement,
  CategoryScale,
  Legend,
  LinearScale,
  Tooltip,
  type ChartOptions,
} from "chart.js";
import type { PointerEvent as ReactPointerEvent } from "react";
import { Bar, Pie } from "react-chartjs-2";

import "./Kpis.css";
import { HttpError, baseHeaders } from "../../lib/api";
import { fetchKpis, type Kpis } from "../../lib/api/metrics";
import { downloadAdminActivityCsv } from "../../lib/api/reports";
import { DEFAULT_TIME_FORMAT, formatTimestamp } from "../../lib/formatters";
import { getCachedThemePrefs, updateThemePrefs } from "../../theme/themeManager";
import type { ThemeUserPrefs } from "../admin/themeData";

ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement, Tooltip, Legend);

export const DASHBOARD_TOGGLE_EDIT_MODE_EVENT = "dashboard-toggle-edit-mode";
export const DASHBOARD_EDIT_MODE_STATE_EVENT = "dashboard-edit-mode-state";

const GRID_SIZE = 150;
const GRID_BOUNDARY = 100;

type WidgetType = "auth-activity" | "evidence-types" | "admin-activity";

type StoredWidget = {
  id?: string | null;
  type?: string;
  x?: number;
  y?: number;
  w?: number;
  h?: number;
};

type PrefsResponse = {
  ok?: boolean;
  prefs?: ThemeUserPrefs;
  etag?: string | null;
  current_etag?: string | null;
};

const WIDGET_TYPE_SET: ReadonlySet<WidgetType> = new Set(["auth-activity", "evidence-types", "admin-activity"]);

const MIN_DIMENSION = 1;

function isWidgetType(value: unknown): value is WidgetType {
  return typeof value === "string" && WIDGET_TYPE_SET.has(value as WidgetType);
}

function toCoordinate(value: unknown): number {
  const num = Number(value);
  if (!Number.isFinite(num)) return 0;
  const rounded = Math.round(num);
  const max = GRID_BOUNDARY - MIN_DIMENSION;
  if (rounded < 0) return 0;
  if (rounded > max) return max;
  return rounded;
}

function toSpan(value: unknown): number {
  const num = Number(value);
  if (!Number.isFinite(num)) return MIN_DIMENSION;
  const rounded = Math.max(MIN_DIMENSION, Math.round(num));
  if (rounded > GRID_BOUNDARY) return GRID_BOUNDARY;
  return rounded;
}

function ensureUniqueId(candidate: string, seen: Set<string>): string {
  let token = candidate.trim().slice(0, 100) || `widget-${seen.size}`;
  while (seen.has(token)) {
    token = `${token}-${seen.size}`;
  }
  return token;
}

function hydrateStoredWidgets(stored: StoredWidget[]): WidgetInstance[] {
  const instances: WidgetInstance[] = [];
  const seenIds = new Set<string>();

  for (const item of stored) {
    if (!item || typeof item !== "object") continue;
    if (!isWidgetType(item.type)) continue;
    const definition = WIDGET_DEFINITIONS[item.type];
    const x = toCoordinate(item.x);
    const y = toCoordinate(item.y);
    const width = Math.min(toSpan(item.w), GRID_BOUNDARY - x);
    const height = Math.min(toSpan(item.h), GRID_BOUNDARY - y);
    const rect: WidgetRect = { x, y, w: Math.max(width, MIN_DIMENSION), h: Math.max(height, MIN_DIMENSION) };
    const sanitized = sanitizeRect(rect, definition, true);
    const baseInstance = createWidgetInstance(item.type, sanitized);
    const desiredId = typeof item.id === "string" ? item.id : baseInstance.id;
    const uniqueId = ensureUniqueId(desiredId, seenIds);
    const instance: WidgetInstance = {
      ...baseInstance,
      id: uniqueId,
      home: { ...sanitized },
      position: { ...sanitized },
    };
    seenIds.add(uniqueId);
    instances.push(instance);
    if (instances.length >= 100) {
      break;
    }
  }

  return instances;
}

function serializeWidgets(widgets: readonly WidgetInstance[]): StoredWidget[] {
  return widgets.map((widget) => ({
    id: widget.id,
    type: widget.type,
    x: Math.round(widget.position.x),
    y: Math.round(widget.position.y),
    w: Math.round(widget.position.w),
    h: Math.round(widget.position.h),
  }));
}

async function safeParseJson<T>(res: Response): Promise<T | null> {
  try {
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

type WidgetSize = {
  w: number;
  h: number;
};

type WidgetRect = WidgetSize & {
  x: number;
  y: number;
};

type WidgetDefinition = {
  type: WidgetType;
  name: string;
  visualization: string;
  description: string;
  minSize: WidgetSize;
  maxSize: WidgetSize;
  defaultSize: WidgetSize;
};

type WidgetInstance = {
  id: string;
  type: WidgetType;
  position: WidgetRect;
  home: WidgetRect;
};

type DragGesture = "move" | "resize";

type DragState = {
  widgetId: string;
  widgetType: WidgetType;
  pointerId: number;
  originX: number;
  originY: number;
  startRect: WidgetRect;
  gesture: DragGesture;
  definition: WidgetDefinition;
};

type ChartDataset = {
  labels: string[];
  queryDates: string[];
  success: number[];
  failed: number[];
};

type ResolveParams = {
  overrides: Map<string, WidgetRect>;
  activeId: string | null;
  commitHome: boolean;
};

const WIDGET_DEFINITIONS: Record<WidgetType, WidgetDefinition> = {
  "auth-activity": {
    type: "auth-activity",
    name: "Authentication Activity",
    visualization: "Bar",
    description: "Stacked daily success vs failure over the selected window.",
    minSize: { w: 1, h: 1 },
    maxSize: { w: GRID_BOUNDARY, h: GRID_BOUNDARY },
    defaultSize: { w: 8, h: 2 },
  },
  "evidence-types": {
    type: "evidence-types",
    name: "Evidence File Types",
    visualization: "Pie",
    description: "Distribution of uploaded evidence by MIME type.",
    minSize: { w: 1, h: 1 },
    maxSize: { w: GRID_BOUNDARY, h: GRID_BOUNDARY },
    defaultSize: { w: 4, h: 2 },
  },
  "admin-activity": {
    type: "admin-activity",
    name: "Admin Activity",
    visualization: "Table",
    description: "Recent activity for admin users, including last login time.",
    minSize: { w: 1, h: 1 },
    maxSize: { w: GRID_BOUNDARY, h: GRID_BOUNDARY },
    defaultSize: { w: 4, h: 2 },
  },
};

let widgetSequence = 0;

function clamp(value: number, min: number, max: number): number {
  if (value < min) return min;
  if (value > max) return max;
  return value;
}

function sanitizeRect(rect: WidgetRect, definition: WidgetDefinition, snap = true): WidgetRect {
  const minW = clamp(definition.minSize.w, 1, GRID_BOUNDARY);
  const minH = clamp(definition.minSize.h, 1, GRID_BOUNDARY);
  const maxW = clamp(definition.maxSize.w, minW, GRID_BOUNDARY);
  const maxH = clamp(definition.maxSize.h, minH, GRID_BOUNDARY);

  const rawWidth = snap ? Math.round(rect.w) : rect.w;
  const rawHeight = snap ? Math.round(rect.h) : rect.h;
  const width = clamp(rawWidth, minW, maxW);
  const height = clamp(rawHeight, minH, maxH);

  const maxX = GRID_BOUNDARY - width;
  const maxY = GRID_BOUNDARY - height;

  const rawX = snap ? Math.round(rect.x) : rect.x;
  const rawY = snap ? Math.round(rect.y) : rect.y;

  const x = clamp(rawX, 0, Math.max(0, maxX));
  const y = clamp(rawY, 0, Math.max(0, maxY));

  if (snap) {
    return { x, y, w: width, h: height };
  }

  return {
    x: Number(x.toFixed(4)),
    y: Number(y.toFixed(4)),
    w: Number(width.toFixed(4)),
    h: Number(height.toFixed(4)),
  };
}

function cellKey(x: number, y: number): string {
  return `${x}:${y}`;
}

function fitsWithinBounds(rect: WidgetRect): boolean {
  return (
    rect.x >= 0 &&
    rect.y >= 0 &&
    rect.x + rect.w <= GRID_BOUNDARY &&
    rect.y + rect.h <= GRID_BOUNDARY
  );
}

function areaIsFree(rect: WidgetRect, occupancy: Set<string>): boolean {
  for (let y = rect.y; y < rect.y + rect.h; y += 1) {
    for (let x = rect.x; x < rect.x + rect.w; x += 1) {
      if (occupancy.has(cellKey(x, y))) {
        return false;
      }
    }
  }
  return true;
}

function occupy(rect: WidgetRect, occupancy: Set<string>): void {
  for (let y = rect.y; y < rect.y + rect.h; y += 1) {
    for (let x = rect.x; x < rect.x + rect.w; x += 1) {
      occupancy.add(cellKey(x, y));
    }
  }
}

function findNearestPlacement(target: WidgetRect, occupancy: Set<string>): WidgetRect {
  if (fitsWithinBounds(target) && areaIsFree(target, occupancy)) {
    return target;
  }

  const visited = new Set<string>();
  const queue: WidgetRect[] = [{ ...target }];
  visited.add(cellKey(target.x, target.y));
  const directions = [
    { dx: 1, dy: 0 },
    { dx: -1, dy: 0 },
    { dx: 0, dy: 1 },
    { dx: 0, dy: -1 },
  ];

  while (queue.length > 0) {
    const next = queue.shift() as WidgetRect;
    if (fitsWithinBounds(next) && areaIsFree(next, occupancy)) {
      return next;
    }
    for (const { dx, dy } of directions) {
      const candidate = { ...next, x: next.x + dx, y: next.y + dy };
      if (candidate.x < 0 || candidate.y < 0) continue;
      if (candidate.x + candidate.w > GRID_BOUNDARY || candidate.y + candidate.h > GRID_BOUNDARY) {
        continue;
      }
      const key = cellKey(candidate.x, candidate.y);
      if (visited.has(key)) continue;
      visited.add(key);
      queue.push(candidate);
    }
  }

  return target;
}

function cloneWidgets(widgets: readonly WidgetInstance[]): WidgetInstance[] {
  return widgets.map((widget) => ({
    ...widget,
    position: { ...widget.position },
    home: { ...widget.home },
  }));
}

function resolveLayout(widgets: readonly WidgetInstance[], params: ResolveParams): WidgetInstance[] {
  const { overrides, activeId, commitHome } = params;
  const occupancy = new Set<string>();
  const placements = new Map<string, WidgetRect>();
  const ordered = widgets
    .map((widget, index) => ({ widget, index }))
    .sort((a, b) => {
      if (a.widget.id === activeId) return -1;
      if (b.widget.id === activeId) return 1;
      return a.index - b.index;
    });

  for (const item of ordered) {
    const widget = item.widget;
    const definition = WIDGET_DEFINITIONS[widget.type];
    const sourceRect = overrides.get(widget.id) ?? widget.home ?? widget.position;
    const sanitized = sanitizeRect(sourceRect, definition, true);
    const placed = findNearestPlacement(sanitized, occupancy);
    placements.set(widget.id, placed);
    occupy(placed, occupancy);
  }

  return widgets.map((widget) => {
    const placed = placements.get(widget.id) ?? widget.position;
    const isActive = widget.id === activeId;
    return {
      ...widget,
      position: { ...placed },
      home:
        isActive && commitHome && overrides.has(widget.id)
          ? { ...placed }
          : { ...widget.home },
    };
  });
}

function createWidgetInstance(type: WidgetType, rect?: WidgetRect): WidgetInstance {
  const definition = WIDGET_DEFINITIONS[type];
  const baseRect: WidgetRect = rect
    ? { ...rect }
    : { x: 0, y: 0, w: definition.defaultSize.w, h: definition.defaultSize.h };
  const sanitized = sanitizeRect(baseRect, definition, true);
  const id = `${type}-${Date.now().toString(36)}-${widgetSequence++}`;
  return {
    id,
    type,
    position: { ...sanitized },
    home: { ...sanitized },
  };
}

function createInitialWidgets(): WidgetInstance[] {
  return [
    createWidgetInstance("auth-activity", { x: 0, y: 0, w: 8, h: 2 }),
    createWidgetInstance("evidence-types", { x: 0, y: 2, w: 4, h: 2 }),
    createWidgetInstance("admin-activity", { x: 4, y: 2, w: 4, h: 2 }),
  ];
}

function rectEquals(a: WidgetRect | null, b: WidgetRect | null, tolerance = 0): boolean {
  if (!a || !b) return false;
  const diff = (valueA: number, valueB: number) => Math.abs(valueA - valueB) <= tolerance;
  return diff(a.x, b.x) && diff(a.y, b.y) && diff(a.w, b.w) && diff(a.h, b.h);
}

function toLocalDayStrings(
  source: string,
  formatter: Intl.DateTimeFormat | null
): { display: string; query: string } {
  if (typeof source !== "string" || source.trim() === "") {
    return { display: "", query: "" };
  }

  const trimmed = source.trim();
  const isoCandidate = /^\d{4}-\d{2}-\d{2}$/.test(trimmed) ? `${trimmed}T00:00:00Z` : trimmed;
  const parsed = new Date(isoCandidate);
  if (Number.isNaN(parsed.valueOf())) {
    return { display: trimmed, query: trimmed };
  }

  const localDate = new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
  const display = formatter ? formatter.format(localDate) : localDate.toLocaleDateString();
  const query = localDate.toISOString().slice(0, 10);

  return { display, query };
}

function buildAuthDataset(kpis: Kpis | null): ChartDataset {
  if (!kpis) {
    return { labels: [], queryDates: [], success: [], failed: [] };
  }

  const daily = kpis.auth_activity.daily;
  const hasIntl = typeof Intl !== "undefined" && typeof Intl.DateTimeFormat === "function";
  const formatter = hasIntl ? new Intl.DateTimeFormat(undefined) : null;

  const labels: string[] = [];
  const queryDates: string[] = [];
  const success: number[] = [];
  const failed: number[] = [];

  for (const entry of daily) {
    const { display, query } = toLocalDayStrings(entry.date, formatter);
    labels.push(display);
    queryDates.push(query);
    success.push(entry.success);
    failed.push(entry.failed);
  }

  return {
    labels,
    queryDates,
    success,
    failed,
  };
}

function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
      return false;
    }
    return window.matchMedia(query).matches;
  });

  useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
      return;
    }
    const mediaQuery = window.matchMedia(query);
    const handler = (event: MediaQueryListEvent) => setMatches(event.matches);
    setMatches(mediaQuery.matches);
    if (typeof mediaQuery.addEventListener === "function") {
      mediaQuery.addEventListener("change", handler);
    } else if (typeof mediaQuery.addListener === "function") {
      mediaQuery.addListener(handler);
    }
    return () => {
      if (typeof mediaQuery.removeEventListener === "function") {
        mediaQuery.removeEventListener("change", handler);
      } else if (typeof mediaQuery.removeListener === "function") {
        mediaQuery.removeListener(handler);
      }
    };
  }, [query]);

  return matches;
}

export default function Kpis(): JSX.Element {
  const defaultLayoutRef = useRef<WidgetInstance[]>(createInitialWidgets());
  const [widgets, setWidgets] = useState<WidgetInstance[]>(() =>
    cloneWidgets(defaultLayoutRef.current)
  );
  const [savedWidgets, setSavedWidgets] = useState<WidgetInstance[]>(() =>
    cloneWidgets(defaultLayoutRef.current)
  );
  const savedWidgetsRef = useRef<WidgetInstance[]>(cloneWidgets(defaultLayoutRef.current));
  const hasStoredLayoutRef = useRef(false);
  const [floatingRects, setFloatingRects] = useState<Record<string, WidgetRect>>({});
  const [activeDrag, setActiveDrag] = useState<DragState | null>(null);
  const dragStateRef = useRef<DragState | null>(null);
  const lastSnappedRef = useRef<WidgetRect | null>(null);
  const prefsEtagRef = useRef<string | null>(null);
  const isMountedRef = useRef(true);

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [kpis, setKpis] = useState<Kpis | null>(null);
  const [downloadingReport, setDownloadingReport] = useState(false);
  const [reportError, setReportError] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [showWidgetModal, setShowWidgetModal] = useState(false);
  const [layoutError, setLayoutError] = useState<string | null>(null);
  const [layoutNotice, setLayoutNotice] = useState<string | null>(null);
  const [savingLayout, setSavingLayout] = useState(false);
  const [prefsLoaded, setPrefsLoaded] = useState(false);
  const [canEditDashboard, setCanEditDashboard] = useState(true);

  const navigate = useNavigate();
  const twoColumnLayout = useMediaQuery("(min-width: 1200px)");

  useEffect(() => {
    return () => {
      isMountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    savedWidgetsRef.current = cloneWidgets(savedWidgets);
  }, [savedWidgets]);

  useEffect(() => {
    if (!layoutNotice) return undefined;
    const timer = window.setTimeout(() => {
      if (isMountedRef.current) {
        setLayoutNotice(null);
      }
    }, 2000);
    return () => {
      window.clearTimeout(timer);
    };
  }, [layoutNotice]);

  const loadDashboardPrefs = useCallback(
    async (options: { apply?: boolean } = {}): Promise<ThemeUserPrefs | null> => {
      const apply = options.apply ?? true;
      let res: Response;
      try {
        res = await fetch("/api/me/prefs/ui", {
          method: "GET",
          credentials: "same-origin",
          headers: baseHeaders(),
        });
      } catch {
        if (apply && isMountedRef.current) {
          setLayoutError("Unable to load dashboard layout.");
          setPrefsLoaded(true);
        }
        return null;
      }

      const body = await safeParseJson<PrefsResponse>(res);
      const resolveEtag = (): string | null => res.headers.get("ETag") ?? body?.etag ?? null;

      if (!isMountedRef.current) {
        prefsEtagRef.current = resolveEtag();
        return body?.prefs ?? null;
      }

      prefsEtagRef.current = resolveEtag();

      if (res.status === 403) {
        if (apply) {
          setLayoutError("You do not have permission to customize the dashboard.");
          setPrefsLoaded(true);
          setCanEditDashboard(false);
        }
        return body?.prefs ?? null;
      }

      if (res.status === 401) {
        if (apply) {
          setPrefsLoaded(true);
        }
        return body?.prefs ?? null;
      }

      if (!res.ok) {
        if (apply) {
          setLayoutError("Unable to load dashboard layout.");
          setPrefsLoaded(true);
        }
        return body?.prefs ?? null;
      }

      if (apply) {
        setLayoutError(null);
        setLayoutNotice(null);
        setCanEditDashboard(true);
        const prefs = body?.prefs ?? getCachedThemePrefs();
        if (body?.prefs) {
          updateThemePrefs(body.prefs);
        }

        const rawWidgets = prefs.dashboard?.widgets;
        const storedWidgets = Array.isArray(rawWidgets) ? (rawWidgets as StoredWidget[]) : null;
        if (storedWidgets === null) {
          hasStoredLayoutRef.current = false;
        } else if (storedWidgets.length > 0) {
          hasStoredLayoutRef.current = true;
        }
        const hydrated = (() => {
          if (storedWidgets === null) {
            return cloneWidgets(defaultLayoutRef.current);
          }
          if (storedWidgets.length === 0) {
            return hasStoredLayoutRef.current ? ([] as WidgetInstance[]) : cloneWidgets(defaultLayoutRef.current);
          }
          return hydrateStoredWidgets(storedWidgets);
        })();

        const display = hydrated.length > 0 ? cloneWidgets(hydrated) : [];
        setWidgets(display);
        const saved = display.length > 0 ? cloneWidgets(display) : [];
        setSavedWidgets(saved);
        savedWidgetsRef.current = cloneWidgets(saved);
        setPrefsLoaded(true);
      }

      return body?.prefs ?? null;
    },
    []
  );

  useEffect(() => {
    void loadDashboardPrefs({ apply: true });
  }, [loadDashboardPrefs]);

  useEffect(() => {
    let aborted = false;
    const controller = new AbortController();

    const run = async () => {
      try {
        setLoading(true);
        setError(null);
        const data = await fetchKpis(controller.signal);
        if (!aborted) {
          setKpis(data);
        }
      } catch (err: unknown) {
        if (aborted) return;
        if (err instanceof HttpError) {
          if (err.status === 401) setError("You must log in to view KPIs.");
          else if (err.status === 403) setError("You do not have access to KPIs.");
          else setError(`Request failed (HTTP ${err.status}).`);
        } else if (err instanceof Error && err.name === "AbortError") {
          // ignore
        } else {
          setError("Network error.");
        }
        setKpis(null);
      } finally {
        if (!aborted) setLoading(false);
      }
    };

    void run();

    return () => {
      aborted = true;
      controller.abort();
    };
  }, []);

  const ready = !loading && !error && Boolean(kpis) && prefsLoaded && canEditDashboard;

  const updateFloatingRect = useCallback((widgetId: string, rect: WidgetRect | null) => {
    setFloatingRects((prev) => {
      if (rect === null) {
        if (!(widgetId in prev)) return prev;
        const next = { ...prev };
        delete next[widgetId];
        return next;
      }
      const existing = prev[widgetId] ?? null;
      if (rectEquals(existing, rect, 0.0001)) {
        return prev;
      }
      return { ...prev, [widgetId]: rect };
    });
  }, []);

  useEffect(() => {
    if (!isEditing) {
      setFloatingRects({});
      dragStateRef.current = null;
      setActiveDrag(null);
      lastSnappedRef.current = null;
      setShowWidgetModal(false);
    }
  }, [isEditing]);

  useEffect(() => {
    const detail = { editing: isEditing, ready };
    window.dispatchEvent(new CustomEvent(DASHBOARD_EDIT_MODE_STATE_EVENT, { detail }));
    return () => {
      window.dispatchEvent(
        new CustomEvent(DASHBOARD_EDIT_MODE_STATE_EVENT, { detail: { editing: false, ready: false } })
      );
    };
  }, [isEditing, ready]);

  const enterEditMode = useCallback(() => {
    if (!ready) return;
    setLayoutNotice(null);
    setLayoutError(null);
    setWidgets(cloneWidgets(savedWidgetsRef.current));
    setIsEditing(true);
  }, [ready]);

  const exitWithoutSaving = useCallback(() => {
    setWidgets(cloneWidgets(savedWidgetsRef.current));
    setIsEditing(false);
    setFloatingRects({});
    dragStateRef.current = null;
    setActiveDrag(null);
    lastSnappedRef.current = null;
  }, []);

  const handleToggleEdit = useCallback(() => {
    if (isEditing) {
      exitWithoutSaving();
    } else {
      enterEditMode();
    }
  }, [enterEditMode, exitWithoutSaving, isEditing]);

  useEffect(() => {
    const listener = () => {
      if (isEditing || ready) {
        handleToggleEdit();
      }
    };
    window.addEventListener(DASHBOARD_TOGGLE_EDIT_MODE_EVENT, listener);
    return () => {
      window.removeEventListener(DASHBOARD_TOGGLE_EDIT_MODE_EVENT, listener);
    };
  }, [handleToggleEdit, isEditing, ready]);

  const handleSave = useCallback(async () => {
    if (savingLayout) return;
    const serialized = serializeWidgets(widgets);
    setSavingLayout(true);
    setLayoutError(null);
    setLayoutNotice(null);

    const ensureEtag = async () => {
      if (!prefsEtagRef.current) {
        await loadDashboardPrefs({ apply: false });
      }
    };

    try {
      await ensureEtag();
      const res = await fetch("/api/me/prefs/ui", {
        method: "PUT",
        credentials: "same-origin",
        headers: baseHeaders({
          "Content-Type": "application/json",
          "If-Match": prefsEtagRef.current ?? "",
        }),
        body: JSON.stringify({ dashboard: { widgets: serialized } }),
      });

      const body = await safeParseJson<PrefsResponse>(res);
      const resolveEtag = (): string | null => res.headers.get("ETag") ?? body?.etag ?? null;

      if (res.status === 409) {
        prefsEtagRef.current = body?.current_etag ?? resolveEtag();
        await loadDashboardPrefs({ apply: true });
        if (isMountedRef.current) {
          setIsEditing(false);
          setLayoutError("Dashboard layout was updated elsewhere. Latest layout loaded.");
        }
        return;
      }

      if (res.status === 403) {
        prefsEtagRef.current = resolveEtag();
        if (isMountedRef.current) {
          setIsEditing(false);
          setLayoutError("You do not have permission to save the dashboard layout.");
        }
        return;
      }

      if (!res.ok) {
        throw new Error(`Save failed (HTTP ${res.status})`);
      }

      prefsEtagRef.current = resolveEtag();
      if (body?.prefs) {
        updateThemePrefs(body.prefs);
      }

      const stored = body?.prefs?.dashboard?.widgets;
      const storedWidgets = Array.isArray(stored) ? (stored as StoredWidget[]) : null;
      const nextWidgetsSource = (() => {
        if (storedWidgets === null) {
          return cloneWidgets(widgets);
        }
        if (storedWidgets.length === 0) {
          return [] as WidgetInstance[];
        }
        return hydrateStoredWidgets(storedWidgets);
      })();
      const normalized = nextWidgetsSource.length > 0 ? nextWidgetsSource : [];

      const display = normalized.length > 0 ? cloneWidgets(normalized) : [];
      hasStoredLayoutRef.current = true;
      if (!isMountedRef.current) return;
      setWidgets(display);
      const saved = display.length > 0 ? cloneWidgets(display) : [];
      setSavedWidgets(saved);
      savedWidgetsRef.current = cloneWidgets(saved);
      setIsEditing(false);
      setFloatingRects({});
      dragStateRef.current = null;
      setActiveDrag(null);
      lastSnappedRef.current = null;
      setLayoutNotice("Dashboard layout saved.");
    } catch {
      if (isMountedRef.current) {
        setLayoutError("Failed to save dashboard layout.");
      }
    } finally {
      if (isMountedRef.current) {
        setSavingLayout(false);
      }
    }
  }, [loadDashboardPrefs, savingLayout, widgets]);

  const handleDefault = useCallback(() => {
    const defaults = cloneWidgets(defaultLayoutRef.current);
    setLayoutNotice(null);
    setLayoutError(null);
    setWidgets(defaults);
    setFloatingRects({});
  }, []);

  const handleRemoveWidget = useCallback((widgetId: string) => {
    setWidgets((current) => current.filter((widget) => widget.id !== widgetId));
    setFloatingRects((prev) => {
      if (!(widgetId in prev)) return prev;
      const next = { ...prev };
      delete next[widgetId];
      return next;
    });
    setLayoutNotice(null);
    setLayoutError(null);
  }, []);

  const handleDownloadAdminReport = useCallback(async () => {
    if (downloadingReport) return;
    try {
      setDownloadingReport(true);
      setReportError(null);
      await downloadAdminActivityCsv();
    } catch (err: unknown) {
      if (err instanceof HttpError) {
        if (err.status === 401) setReportError("You must log in to download the report.");
        else if (err.status === 403) setReportError("You do not have access to this report.");
        else setReportError(`Download failed (HTTP ${err.status}).`);
      } else {
        setReportError("Network error while downloading report.");
      }
    } finally {
      setDownloadingReport(false);
    }
  }, [downloadingReport]);

  const applyTentativeLayout = useCallback(
    (widgetId: string, candidate: WidgetRect, commitHome: boolean) => {
      setWidgets((current) =>
        resolveLayout(current, {
          overrides: new Map([[widgetId, { ...candidate }]]),
          activeId: widgetId,
          commitHome,
        })
      );
    },
    []
  );

  const computeCandidate = useCallback((state: DragState, clientX: number, clientY: number) => {
    const { gesture, startRect, originX, originY, definition } = state;
    if (gesture === "move") {
      const deltaX = (clientX - originX) / GRID_SIZE;
      const deltaY = (clientY - originY) / GRID_SIZE;
      const floatingRect = sanitizeRect(
        {
          ...startRect,
          x: startRect.x + deltaX,
          y: startRect.y + deltaY,
        },
        definition,
        false
      );
      const snapped = sanitizeRect(floatingRect, definition, true);
      return { floating: floatingRect, snapped };
    }
    const deltaW = (clientX - originX) / GRID_SIZE;
    const deltaH = (clientY - originY) / GRID_SIZE;
    const floatingRect = sanitizeRect(
      {
        x: startRect.x,
        y: startRect.y,
        w: startRect.w + deltaW,
        h: startRect.h + deltaH,
      },
      definition,
      false
    );
    const snapped = sanitizeRect(floatingRect, definition, true);
    return { floating: floatingRect, snapped };
  }, []);

  const handlePointerMove = useCallback(
    (event: ReactPointerEvent<HTMLElement>) => {
      const state = dragStateRef.current;
      if (!state || state.pointerId !== event.pointerId) return;
      event.preventDefault();
      const { floating, snapped } = computeCandidate(state, event.clientX, event.clientY);
      updateFloatingRect(state.widgetId, floating);
      if (!rectEquals(snapped, lastSnappedRef.current)) {
        lastSnappedRef.current = snapped;
        applyTentativeLayout(state.widgetId, snapped, false);
      }
    },
    [applyTentativeLayout, computeCandidate, updateFloatingRect]
  );

  const releasePointerCaptureSafe = (target: EventTarget & HTMLElement, pointerId: number) => {
    if (typeof target.hasPointerCapture === "function" && !target.hasPointerCapture(pointerId)) {
      return;
    }
    if (typeof target.releasePointerCapture === "function") {
      target.releasePointerCapture(pointerId);
    }
  };

  const finishGesture = useCallback(
    (event: ReactPointerEvent<HTMLElement>, cancelled: boolean) => {
      const state = dragStateRef.current;
      if (!state || state.pointerId !== event.pointerId) return;
      event.preventDefault();
      let snapped = lastSnappedRef.current;
      if (!cancelled) {
        const candidates = computeCandidate(state, event.clientX, event.clientY);
        snapped = candidates.snapped;
      }
      if (!snapped) {
        snapped = sanitizeRect(state.startRect, state.definition, true);
      }
      updateFloatingRect(state.widgetId, null);
      applyTentativeLayout(state.widgetId, snapped, !cancelled);
      dragStateRef.current = null;
      lastSnappedRef.current = null;
      setActiveDrag(null);
      releasePointerCaptureSafe(event.currentTarget, event.pointerId);
    },
    [applyTentativeLayout, computeCandidate, updateFloatingRect]
  );

  const startGesture = useCallback(
    (
      widget: WidgetInstance,
      definition: WidgetDefinition,
      gesture: DragGesture,
      event: ReactPointerEvent<HTMLElement>
    ) => {
      if (!isEditing) return;
      event.preventDefault();
      event.stopPropagation();
      const state: DragState = {
        widgetId: widget.id,
        widgetType: widget.type,
        pointerId: event.pointerId,
        originX: event.clientX,
        originY: event.clientY,
        startRect: { ...widget.position },
        gesture,
        definition,
      };
      dragStateRef.current = state;
      lastSnappedRef.current = state.startRect;
      setActiveDrag(state);
      if (typeof event.currentTarget.setPointerCapture === "function") {
        event.currentTarget.setPointerCapture(event.pointerId);
      }
    },
    [isEditing]
  );

  const handleAddWidgets = useCallback((types: WidgetType[]) => {
    if (types.length === 0) return;
    setLayoutNotice(null);
    setLayoutError(null);
    setWidgets((current) => {
      let next = cloneWidgets(current);
      for (const type of types) {
        const definition = WIDGET_DEFINITIONS[type];
        const instance = createWidgetInstance(type, {
          x: 0,
          y: 0,
          w: definition.defaultSize.w,
          h: definition.defaultSize.h,
        });
        next = resolveLayout([...next, instance], {
          overrides: new Map([[instance.id, { ...instance.home }]]),
          activeId: instance.id,
          commitHome: true,
        });
      }
      return next;
    });
  }, []);

  const authDataset = useMemo(() => buildAuthDataset(kpis), [kpis]);

  const authDays = kpis?.auth_activity.window_days ?? 7;
  const maxDaily = Math.max(0, kpis?.auth_activity.max_daily_total ?? 0);
  const yMax = Math.max(1, maxDaily + 1);

  const authBarOptions: ChartOptions<"bar"> = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      onClick: (_, elements) => {
        if (elements.length === 0) return;
        const index = elements[0].index;
        const date = authDataset.queryDates[index];
        if (!date) return;
        const params = new URLSearchParams({
          category: "AUTH",
          occurred_from: date,
          occurred_to: date,
        });
        navigate(`/admin/audit?${params.toString()}`);
      },
      plugins: {
        legend: { position: "top" },
        tooltip: {
          callbacks: {
            title: (items) => (items[0]?.label ?? ""),
          },
        },
      },
      scales: {
        x: {
          stacked: true,
          title: { display: true, text: "Date" },
          grid: { display: false },
        },
        y: {
          stacked: true,
          beginAtZero: true,
          max: yMax,
          ticks: {
            stepSize: Math.max(1, Math.ceil(yMax / 6)),
          },
          title: { display: true, text: "Authentications" },
        },
      },
    }),
    [authDataset.queryDates, navigate, yMax]
  );

  const authBarData = useMemo(
    () => ({
      labels: authDataset.labels,
      datasets: [
        {
          label: "Success",
          data: authDataset.success,
          backgroundColor: "#198754",
          borderColor: "#146c43",
          borderWidth: 1,
        },
        {
          label: "Failed",
          data: authDataset.failed,
          backgroundColor: "#dc3545",
          borderColor: "#b02a37",
          borderWidth: 1,
        },
      ],
    }),
    [authDataset]
  );

  const pieSlices = useMemo(() => kpis?.evidence_mime.by_mime ?? [], [kpis]);

  const pieData = useMemo(() => {
    if (pieSlices.length === 0) return null;
    const labels = pieSlices.map((slice) => slice.mime_label ?? slice.mime);
    const counts = pieSlices.map((slice) => slice.count);
    const colors = [
      "#0d6efd",
      "#6f42c1",
      "#20c997",
      "#fd7e14",
      "#6610f2",
      "#ffca2c",
    ];
    return {
      labels,
      datasets: [
        {
          data: counts,
          backgroundColor: labels.map((_, idx) => colors[idx % colors.length]),
          borderColor: "#ffffff",
          borderWidth: 1,
        },
      ],
    };
  }, [pieSlices]);

  const legendPosition = twoColumnLayout ? "left" : "top";
  const pieOptions: ChartOptions<"pie"> = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: legendPosition,
          align: "start",
          labels: {
            usePointStyle: true,
            boxWidth: 12,
          },
        },
      },
      onClick: (_, elements) => {
        if (!pieData || elements.length === 0) return;
        const index = elements[0].index;
        const slice = pieSlices[index];
        if (!slice) return;
        const friendly = slice.mime_label ?? slice.mime;
        const params = new URLSearchParams({ mime_label: friendly });
        navigate(`/admin/evidence?${params.toString()}`);
      },
    }),
    [legendPosition, pieData, pieSlices, navigate]
  );

  const auditLink = useMemo(() => {
    const params = new URLSearchParams({ category: "AUTH" });
    return `/admin/audit?${params.toString()}`;
  }, []);

  const evidenceLink = "/admin/evidence";

  const authTotals = kpis?.auth_activity.totals ?? { success: 0, failed: 0, total: 0 };
  const admins = kpis?.admin_activity.admins ?? [];

  const draggingId = activeDrag?.widgetId ?? null;

  const boardWidthSquares = Math.max(
    4,
    ...widgets.map((widget) => {
      const floating = floatingRects[widget.id];
      const rect = floating ?? widget.position;
      return rect.x + rect.w;
    })
  );

  const boardHeightSquares = Math.max(
    3,
    ...widgets.map((widget) => {
      const floating = floatingRects[widget.id];
      const rect = floating ?? widget.position;
      return rect.y + rect.h;
    })
  );

  const gridClassName = isEditing ? "dashboard-grid editing" : "dashboard-grid";

  return (
    <div className="container py-3">
      <h1 className="mb-3">Dashboard</h1>
      {isEditing && (
        <div className="dashboard-controls d-flex align-items-center gap-2">
          <button
            type="button"
            className="btn btn-outline-secondary btn-icon"
            onClick={() => setShowWidgetModal(true)}
            title="Add widgets to the dashboard"
            aria-label="Open widget picker"
          >
            <i className="bi bi-pie-chart-fill" aria-hidden="true" />
            <span className="visually-hidden">Open widget picker</span>
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-icon"
            onClick={handleDefault}
            title="Reset layout to default arrangement"
            aria-label="Reset dashboard layout"
          >
            <i className="bi bi-arrow-counterclockwise" aria-hidden="true" />
            <span className="visually-hidden">Reset dashboard layout</span>
          </button>
          <button
            type="button"
            className="btn btn-primary btn-icon"
            onClick={() => void handleSave()}
            title="Save the current layout"
            aria-label="Save dashboard layout"
            disabled={savingLayout}
            aria-busy={savingLayout}
          >
            <i className="bi bi-floppy" aria-hidden="true" />
            <span className="visually-hidden">Save dashboard layout</span>
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-icon"
            onClick={exitWithoutSaving}
            title="Discard layout changes"
            aria-label="Discard dashboard layout changes"
          >
            <i className="bi bi-x" aria-hidden="true" />
            <span className="visually-hidden">Discard dashboard layout changes</span>
          </button>
        </div>
      )}

      {layoutError && <div className="alert alert-warning" role="alert">{layoutError}</div>}
      {!layoutError && layoutNotice && (
        <div className="alert alert-success" role="status">{layoutNotice}</div>
      )}

      {loading && <p>Loading…</p>}
      {!loading && error && <div className="alert alert-warning" role="alert">{error}</div>}

      {!loading && !error && kpis && (
        <>
          {widgets.length === 0 ? (
            <div className="dashboard-widget-placeholder rounded border" aria-hidden="true" />
          ) : (
            <div className="dashboard-grid-scroller">
              <div
                className={gridClassName}
                style={{
                  width: `${boardWidthSquares * GRID_SIZE}px`,
                  height: `${boardHeightSquares * GRID_SIZE}px`,
                }}
              >
                {widgets.map((widget) => {
                  const definition = WIDGET_DEFINITIONS[widget.type];
                  const floating = floatingRects[widget.id] ?? null;
                  const rect = floating ?? widget.position;
                  const left = rect.x * GRID_SIZE;
                  const top = rect.y * GRID_SIZE;
                  const width = rect.w * GRID_SIZE;
                  const height = rect.h * GRID_SIZE;

                  const style = {
                    left: `${left}px`,
                    top: `${top}px`,
                    width: `${width}px`,
                    height: `${height}px`,
                    zIndex: draggingId === widget.id ? 2 : 1,
                    transition: draggingId === widget.id ? "none" : undefined,
                  };

                  const headerPointerHandlers = {
                    onPointerDown: (event: ReactPointerEvent<HTMLElement>) =>
                      startGesture(widget, definition, "move", event),
                    onPointerMove: handlePointerMove,
                    onPointerUp: (event: ReactPointerEvent<HTMLElement>) => finishGesture(event, false),
                    onPointerCancel: (event: ReactPointerEvent<HTMLElement>) => finishGesture(event, true),
                  };

                  return (
                    <div
                      key={widget.id}
                      className={`dashboard-widget ${isEditing ? "editing" : ""}`}
                      style={style}
                    >
                      <div className="card shadow-sm h-100">
                        {(widget.type === "auth-activity" || widget.type === "evidence-types") && (
                          <div
                            className="card-header d-flex align-items-center gap-2"
                            {...headerPointerHandlers}
                          >
                            <div className="flex-grow-1">
                              {widget.type === "auth-activity" ? (
                                <Link to={auditLink} className="fw-semibold text-decoration-none">
                                  Authentications last {authDays} days
                                </Link>
                              ) : (
                                <Link to={evidenceLink} className="fw-semibold text-decoration-none">
                                  Evidence File Types
                                </Link>
                              )}
                            </div>
                            {isEditing && (
                              <div className="dashboard-widget-actions">
                                <button
                                  type="button"
                                  className="btn btn-icon btn-sm text-danger"
                                  onPointerDown={(event) => event.stopPropagation()}
                                  onClick={() => handleRemoveWidget(widget.id)}
                                  title={`Remove ${definition.name}`}
                                  aria-label={`Remove ${definition.name}`}
                                >
                                  <i className="bi bi-trash" aria-hidden="true" />
                                  <span className="visually-hidden">Remove {definition.name}</span>
                                </button>
                              </div>
                            )}
                          </div>
                        )}

                        {widget.type === "admin-activity" && (
                          <div
                            className="card-header d-flex align-items-center gap-2 justify-content-between"
                            {...headerPointerHandlers}
                          >
                            <div className="flex-grow-1">
                              <Link to={`${auditLink}&role=Admin`} className="fw-semibold text-decoration-none">
                                Admin Activity
                              </Link>
                            </div>
                            {isEditing && (
                              <div className="dashboard-widget-actions">
                                <button
                                  type="button"
                                  className="btn btn-icon btn-sm text-danger"
                                  onPointerDown={(event) => event.stopPropagation()}
                                  onClick={() => handleRemoveWidget(widget.id)}
                                  title={`Remove ${definition.name}`}
                                  aria-label={`Remove ${definition.name}`}
                                >
                                  <i className="bi bi-trash" aria-hidden="true" />
                                  <span className="visually-hidden">Remove {definition.name}</span>
                                </button>
                              </div>
                            )}
                          </div>
                        )}

                        {widget.type === "auth-activity" && (
                          <div className="card-body">
                            {authDataset.labels.length === 0 ? (
                              <p className="text-muted mb-0">No authentication activity in the selected window.</p>
                            ) : (
                              <div className="position-relative h-100">
                                <Bar
                                  options={authBarOptions}
                                  data={authBarData}
                                  aria-label="Authentication activity"
                                />
                              </div>
                            )}
                            <div className="text-muted small mt-3">
                              <span className="me-3">Success: {authTotals.success}</span>
                              <span className="me-3">Failed: {authTotals.failed}</span>
                              <span>Total: {authTotals.total}</span>
                            </div>
                          </div>
                        )}

                        {widget.type === "evidence-types" && (
                          <div className="card-body">
                            {pieData ? (
                              <div className="position-relative h-100">
                                <Pie
                                  data={pieData}
                                  options={pieOptions}
                                  aria-label="Evidence file type distribution"
                                />
                              </div>
                            ) : (
                              <p className="text-muted mb-0">No evidence uploads yet.</p>
                            )}
                          </div>
                        )}

                        {widget.type === "admin-activity" && (
                          <>
                            <div className="card-body d-flex flex-column p-0">
                              {reportError && (
                                <div className="alert alert-warning mb-0 rounded-0 py-2 px-3" role="alert">
                                  {reportError}
                                </div>
                              )}
                              <div className="flex-grow-1 overflow-auto">
                                {admins.length === 0 ? (
                                  <p className="text-muted px-3 py-3 mb-0">No admin users found.</p>
                                ) : (
                                  <div className="table-responsive">
                                    <table className="table table-sm table-striped mb-0">
                                      <thead>
                                        <tr>
                                          <th scope="col">User</th>
                                          <th scope="col">Last Login</th>
                                        </tr>
                                      </thead>
                                      <tbody>
                                        {admins.map((admin) => {
                                          const lastLogin = admin.last_login_at
                                            ? formatTimestamp(admin.last_login_at, DEFAULT_TIME_FORMAT)
                                            : "—";
                                          return (
                                            <tr key={`${admin.id}-${admin.email}`}>
                                              <td>
                                                <div className="fw-semibold">
                                                  {admin.name || admin.email || `User ${admin.id}`}
                                                </div>
                                                {admin.email && <div className="text-muted small">{admin.email}</div>}
                                              </td>
                                              <td>{lastLogin}</td>
                                            </tr>
                                          );
                                        })}
                                      </tbody>
                                    </table>
                                  </div>
                                )}
                              </div>
                            </div>
                            <div className="card-footer text-end bg-transparent border-top">
                              <button
                                type="button"
                                className="btn btn-outline-secondary btn-sm"
                                onClick={handleDownloadAdminReport}
                                disabled={downloadingReport}
                                aria-busy={downloadingReport}
                              >
                                {downloadingReport ? "Downloading…" : "Download CSV"}
                              </button>
                            </div>
                          </>
                        )}
                      </div>

                      {isEditing && (
                        <button
                          type="button"
                          className="btn btn-icon btn-sm dashboard-widget-resize"
                          onPointerDown={(event) => startGesture(widget, definition, "resize", event)}
                          onPointerMove={handlePointerMove}
                          onPointerUp={(event) => finishGesture(event, false)}
                          onPointerCancel={(event) => finishGesture(event, true)}
                          title={`Resize ${definition.name}`}
                          aria-label={`Resize ${definition.name}`}
                        >
                          <i className="bi bi-sticky-fill" aria-hidden="true" />
                          <span className="visually-hidden">Resize {definition.name}</span>
                        </button>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </>
      )}

      <WidgetModal
        open={showWidgetModal}
        onClose={() => setShowWidgetModal(false)}
        onConfirm={(types) => {
          handleAddWidgets(types);
          setShowWidgetModal(false);
        }}
      />
    </div>
  );
}

type WidgetModalProps = {
  open: boolean;
  onClose: () => void;
  onConfirm: (types: WidgetType[]) => void;
};

function WidgetModal({ open, onClose, onConfirm }: WidgetModalProps): JSX.Element | null {
  const catalog = useMemo(() => Object.values(WIDGET_DEFINITIONS), []);
  const [selected, setSelected] = useState<Set<WidgetType>>(new Set());

  useEffect(() => {
    if (open) {
      setSelected(new Set());
    }
  }, [open]);

  if (!open) return null;

  const toggleSelection = (type: WidgetType) => {
    setSelected((previous) => {
      const next = new Set(previous);
      if (next.has(type)) {
        next.delete(type);
      } else {
        next.add(type);
      }
      return next;
    });
  };

  const handleConfirm = () => {
    if (selected.size === 0) return;
    onConfirm(Array.from(selected));
  };

  return (
    <div className="dashboard-modal-backdrop" role="presentation">
      <div
        className="dashboard-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dashboard-widget-picker-title"
      >
        <div className="dashboard-modal-header">
          <h2 id="dashboard-widget-picker-title" className="h5 mb-0">
            Add Widgets
          </h2>
        </div>
        <div className="dashboard-modal-body">
          <div className="list-group">
            {catalog.map((definition) => {
              const checked = selected.has(definition.type);
              return (
                <label
                  key={definition.type}
                  className="list-group-item list-group-item-action d-flex align-items-start gap-3"
                >
                  <input
                    type="checkbox"
                    className="form-check-input mt-1"
                    checked={checked}
                    onChange={() => toggleSelection(definition.type)}
                  />
                  <div>
                    <div className="fw-semibold">{definition.name}</div>
                    <div className="text-muted small">
                      {definition.visualization} · {definition.description}
                    </div>
                    <div className="text-muted small">
                      Default size {definition.defaultSize.h}×{definition.defaultSize.w} grid squares (H×W)
                    </div>
                  </div>
                </label>
              );
            })}
          </div>
        </div>
        <div className="dashboard-modal-footer">
          <button type="button" className="btn btn-outline-secondary" onClick={onClose}>
            Cancel
          </button>
          <button
            type="button"
            className="btn btn-primary"
            onClick={handleConfirm}
            disabled={selected.size === 0}
          >
            Add
          </button>
        </div>
      </div>
    </div>
  );
}
