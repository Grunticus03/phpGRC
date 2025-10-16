import { authMe, type AuthUser } from "./api";

export type ThemeAccess = {
  canView: boolean;
  canManage: boolean;
  canManagePacks: boolean;
  roles: string[];
};

const ROLE_SYNONYMS = {
  admin: new Set(["admin", "role_admin"]),
  themeManager: new Set(["theme manager", "role_theme_manager"]),
  themeAuditor: new Set(["theme auditor", "role_theme_auditor"]),
};

const DEFAULT_ACCESS: ThemeAccess = {
  canView: false,
  canManage: false,
  canManagePacks: false,
  roles: [],
};

export function normalizeRole(role: string): string {
  return role.trim().toLowerCase();
}

export function deriveThemeAccess(rawRoles: string[]): ThemeAccess {
  if (!Array.isArray(rawRoles) || rawRoles.length === 0) {
    return { ...DEFAULT_ACCESS };
  }

  const normalized = rawRoles
    .filter((role): role is string => typeof role === "string")
    .map((role) => normalizeRole(role));

  const hasAdmin = normalized.some((role) => ROLE_SYNONYMS.admin.has(role));
  const hasThemeManager = normalized.some((role) => ROLE_SYNONYMS.themeManager.has(role));
  const hasThemeAuditor = normalized.some((role) => ROLE_SYNONYMS.themeAuditor.has(role));

  const canManage = hasAdmin || hasThemeManager;

  return {
    canView: canManage || hasThemeAuditor,
    canManage,
    canManagePacks: canManage,
    roles: normalized,
  };
}

let cachedAccess: ThemeAccess | null = null;
let inflight: Promise<ThemeAccess> | null = null;

export async function getThemeAccess(): Promise<ThemeAccess> {
  if (cachedAccess) {
    return cachedAccess;
  }

  if (inflight) {
    return inflight;
  }

  inflight = authMe()
    .then((user: AuthUser) => {
      const access = deriveThemeAccess(user.roles ?? []);
      cachedAccess = access;
      return access;
    })
    .catch(() => {
      cachedAccess = { ...DEFAULT_ACCESS };
      return cachedAccess;
    })
    .finally(() => {
      inflight = null;
    });

  return inflight;
}

export function resetThemeAccessCache(): void {
  cachedAccess = null;
  inflight = null;
}
