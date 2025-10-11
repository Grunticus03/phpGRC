export type ModulePlacement = "core" | "sidebar";

export type ModuleMeta = {
  id: string;
  label: string;
  path: string;
  placement: ModulePlacement;
};

const modules: ModuleMeta[] = [
  { id: "dashboard", label: "Dashboard", path: "/dashboard", placement: "sidebar" },
  { id: "risks", label: "Risks", path: "/risks", placement: "core" },
  { id: "compliance", label: "Compliance", path: "/compliance", placement: "core" },
  { id: "audits", label: "Audits", path: "/audits", placement: "core" },
  { id: "policies", label: "Policies", path: "/policies", placement: "core" },
  { id: "evidence", label: "Evidence", path: "/evidence", placement: "sidebar" },
  { id: "exports", label: "Exports", path: "/exports", placement: "sidebar" },
  { id: "admin", label: "Admin", path: "/admin", placement: "sidebar" },
];

const labelSort = (a: ModuleMeta, b: ModuleMeta): number =>
  a.label.localeCompare(b.label, undefined, { sensitivity: "base" });

export const MODULES: readonly ModuleMeta[] = modules;

export const CORE_MODULES: readonly ModuleMeta[] = modules
  .filter((module) => module.placement === "core")
  .sort(labelSort);

export const SIDEBAR_MODULES: readonly ModuleMeta[] = modules.filter(
  (module) => module.placement === "sidebar"
);

export const MODULE_LOOKUP: ReadonlyMap<string, ModuleMeta> = new Map(
  modules.map((module) => [module.id, module])
);

