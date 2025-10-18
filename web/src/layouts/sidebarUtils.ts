import type { ModuleMeta } from "./modules";

export const MIN_SIDEBAR_WIDTH = 50;
export const MAX_SIDEBAR_WIDTH = 4000;
export const MAX_SIDEBAR_WIDTH_RATIO = 0.5;

const round = (value: number): number => Math.round(Number.isFinite(value) ? value : 0);

export function clampSidebarWidth(width: number, viewportWidth?: number): number {
  const baseViewport = Number.isFinite(viewportWidth) && viewportWidth && viewportWidth > 0
    ? viewportWidth
    : typeof window !== "undefined" && Number.isFinite(window.innerWidth)
      ? window.innerWidth
      : MIN_SIDEBAR_WIDTH / MAX_SIDEBAR_WIDTH_RATIO;

  const maxRatioWidth = Math.max(MIN_SIDEBAR_WIDTH, Math.floor(baseViewport * MAX_SIDEBAR_WIDTH_RATIO));
  const maxWidth = Math.max(MIN_SIDEBAR_WIDTH, Math.min(maxRatioWidth, MAX_SIDEBAR_WIDTH));
  const rounded = round(width);
  if (!Number.isFinite(rounded)) return MIN_SIDEBAR_WIDTH;
  return Math.min(Math.max(rounded, MIN_SIDEBAR_WIDTH), maxWidth);
}

const sanitizeId = (value: unknown): string | null => {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
};

export function mergeSidebarOrder(
  modules: readonly ModuleMeta[],
  defaultOrder: readonly string[] | undefined,
  persistedOrder: readonly string[] | undefined
): string[] {
  const byId = new Map(modules.map((module) => [module.id, module]));
  const seen = new Set<string>();

  const collect = (source: readonly string[] | undefined): string[] => {
    if (!source) return [];
    const result: string[] = [];
    for (const item of source) {
      const id = sanitizeId(item);
      if (!id || seen.has(id)) continue;
      if (!byId.has(id)) continue;
      seen.add(id);
      result.push(id);
    }
    return result;
  };

  const persisted = collect(persistedOrder);
  const defaults = collect(defaultOrder);

  const remainder = modules
    .filter((module) => !seen.has(module.id))
    .sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: "base" }))
    .map((module) => module.id);

  return [...persisted, ...defaults, ...remainder];
}
