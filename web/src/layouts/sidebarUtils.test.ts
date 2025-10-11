import { describe, expect, it, beforeEach } from "vitest";
import type { ModuleMeta } from "./modules";
import { clampSidebarWidth, mergeSidebarOrder, MIN_SIDEBAR_WIDTH } from "./sidebarUtils";

const modules: ModuleMeta[] = [
  { id: "alpha", label: "Alpha", path: "/alpha", placement: "sidebar" },
  { id: "beta", label: "Beta", path: "/beta", placement: "sidebar" },
  { id: "gamma", label: "Gamma", path: "/gamma", placement: "sidebar" },
  { id: "delta", label: "Delta", path: "/delta", placement: "sidebar" },
];

describe("mergeSidebarOrder", () => {
  it("places persisted items first, then defaults, then alphabetical remainder", () => {
    const persisted = ["gamma"];
    const defaults = ["delta", "beta"];
    const merged = mergeSidebarOrder(modules, defaults, persisted);
    expect(merged).toEqual(["gamma", "delta", "beta", "alpha"]);
  });

  it("filters unknown or duplicate ids", () => {
    const persisted = ["beta", "unknown", "Beta", "beta"];
    const defaults = ["alpha", "delta", "delta", "omega"];
    const merged = mergeSidebarOrder(modules, defaults, persisted);
    expect(merged).toEqual(["beta", "alpha", "delta", "gamma"]);
  });

  it("returns alphabetical list when no orders provided", () => {
    const merged = mergeSidebarOrder(modules, undefined, undefined);
    expect(merged).toEqual(["alpha", "beta", "delta", "gamma"].sort((a, b) => a.localeCompare(b)));
  });
});

describe("clampSidebarWidth", () => {
  beforeEach(() => {
    if (typeof window !== "undefined") {
      Object.defineProperty(window, "innerWidth", { value: 1200, configurable: true });
    }
  });

  it("enforces minimum width", () => {
    expect(clampSidebarWidth(10, 800)).toBe(MIN_SIDEBAR_WIDTH);
  });

  it("enforces maximum width based on viewport", () => {
    const viewport = 1000;
    const expectedMax = Math.floor(viewport * 0.5);
    expect(clampSidebarWidth(2000, viewport)).toBe(expectedMax);
  });

  it("rounds to nearest integer", () => {
    expect(clampSidebarWidth(199.7, 800)).toBe(200);
  });
});
