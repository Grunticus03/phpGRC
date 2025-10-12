export type ModulePlacement = "navbar" | "sidebar";

export type ModuleMeta = {
  id: string;
  label: string;
  path: string;
  placement: ModulePlacement;
};

const modules: ModuleMeta[] = [
  { id: "dashboard", label: "Dashboard", path: "/dashboard", placement: "navbar" },
  { id: "evidence", label: "Evidence", path: "/evidence", placement: "navbar" },
  { id: "exports", label: "Exports", path: "/exports", placement: "navbar" },
  { id: "admin", label: "Admin", path: "/admin", placement: "navbar" },
  { id: "risks", label: "Risks", path: "/risks", placement: "sidebar" },
  { id: "compliance", label: "Compliance", path: "/compliance", placement: "sidebar" },
  { id: "policies", label: "Policies", path: "/policies", placement: "sidebar" },
];

export const MODULES: readonly ModuleMeta[] = modules;

export const NAVBAR_MODULES: readonly ModuleMeta[] = modules
  .filter((module) => module.placement === "navbar");

export const SIDEBAR_MODULES: readonly ModuleMeta[] = modules.filter(
  (module) => module.placement === "sidebar"
);

export const MODULE_LOOKUP: ReadonlyMap<string, ModuleMeta> = new Map(
  modules.map((module) => [module.id, module])
);
