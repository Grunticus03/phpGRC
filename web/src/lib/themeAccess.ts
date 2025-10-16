import { apiGet, authMe, type AuthUser } from "./api";

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

const FULL_ACCESS: ThemeAccess = {
  canView: true,
  canManage: true,
  canManagePacks: true,
  roles: [],
};

type Fingerprint = {
  summary?: { rbac?: { require_auth?: boolean } };
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
let requireAuthFlag: boolean | null = null;
let requireAuthInflight: Promise<boolean> | null = null;

function cloneAccess(access: ThemeAccess): ThemeAccess {
  return { ...access, roles: [...access.roles] };
}

async function resolveRequireAuth(): Promise<boolean> {
  if (requireAuthFlag !== null) {
    return requireAuthFlag;
  }

  if (requireAuthInflight === null) {
    requireAuthInflight = apiGet<Fingerprint>("/api/health/fingerprint")
      .then((fp) => {
        const requireAuth = Boolean(fp?.summary?.rbac?.require_auth);
        requireAuthFlag = requireAuth;
        return requireAuth;
      })
      .catch(() => {
        requireAuthFlag = false;
        return false;
      })
      .finally(() => {
        requireAuthInflight = null;
      });
  }

  return requireAuthInflight;
}

function prepareFullAccess(): ThemeAccess {
  const access = cloneAccess(FULL_ACCESS);
  cachedAccess = access;
  return access;
}

export function seedThemeRequireAuth(flag: boolean): void {
  requireAuthFlag = flag;
  cachedAccess = flag ? null : prepareFullAccess();
}

export async function getThemeAccess(): Promise<ThemeAccess> {
  if (cachedAccess) {
    return cachedAccess;
  }

  if (inflight) {
    return inflight;
  }

  inflight = resolveRequireAuth()
    .then(async (requireAuth) => {
      if (!requireAuth) {
        return prepareFullAccess();
      }

      try {
        const user: AuthUser = await authMe();
        const access = deriveThemeAccess(user.roles ?? []);
        cachedAccess = access;
        return access;
      } catch {
        cachedAccess = cloneAccess(DEFAULT_ACCESS);
        return cachedAccess;
      }
    })
    .finally(() => {
      inflight = null;
    });

  return inflight;
}

export function resetThemeAccessCache(): void {
  cachedAccess = requireAuthFlag === false ? prepareFullAccess() : null;
  inflight = null;
}
