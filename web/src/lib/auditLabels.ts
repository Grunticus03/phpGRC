export type ActionInfo = {
  label: string;          // Human-friendly text
  category: string;       // Canonical category (e.g., RBAC)
  variant: "neutral" | "success" | "warning" | "danger";
};

const byAction: Record<string, ActionInfo> = {
  // AUTH
  "auth.login.success":        { label: "Login success", category: "AUTH", variant: "success" },
  "auth.login.failed":         { label: "Login failed", category: "AUTH", variant: "warning" },
  "auth.logout":               { label: "Logout", category: "AUTH", variant: "neutral" },
  "auth.mfa.totp.enrolled":    { label: "TOTP enrolled", category: "AUTH", variant: "success" },
  "auth.mfa.totp.verified":    { label: "TOTP verified", category: "AUTH", variant: "success" },
  "auth.bruteforce.locked":    { label: "Brute force locked", category: "AUTH", variant: "danger" },

  // RBAC denies (Phase-5 middleware emits these)
  "rbac.deny.unauthenticated": { label: "Denied: unauthenticated", category: "RBAC", variant: "danger" },
  "rbac.deny.role_mismatch":   { label: "Denied: role", category: "RBAC", variant: "danger" },
  "rbac.deny.policy":          { label: "Denied: policy", category: "RBAC", variant: "danger" },
  "rbac.deny.capability":      { label: "Denied: capability", category: "RBAC", variant: "danger" },
  "rbac.deny.unknown_policy":  { label: "Denied: unknown policy", category: "RBAC", variant: "danger" },

  // RBAC role/user changes
  "rbac.user_role.attached":   { label: "Role attached", category: "RBAC", variant: "success" },
  "rbac.user_role.detached":   { label: "Role detached", category: "RBAC", variant: "warning" },
  "rbac.user_role.replaced":   { label: "Roles replaced", category: "RBAC", variant: "neutral" },
  "rbac.role.created":         { label: "Role created", category: "RBAC", variant: "success" },
  "rbac.role.updated":         { label: "Role updated", category: "RBAC", variant: "neutral" },
  "rbac.role.deleted":         { label: "Role deleted", category: "RBAC", variant: "warning" },

  // EVIDENCE
  "evidence.uploaded":         { label: "Evidence uploaded", category: "EVIDENCE", variant: "success" },
  "evidence.deleted":          { label: "Evidence deleted", category: "EVIDENCE", variant: "warning" },

  // EXPORTS
  "export.job.created":        { label: "Export started", category: "EXPORTS", variant: "neutral" },
  "export.job.completed":      { label: "Export completed", category: "EXPORTS", variant: "success" },
  "export.job.failed":         { label: "Export failed", category: "EXPORTS", variant: "danger" },

  // SETTINGS
  "settings.updated":          { label: "Settings updated", category: "SETTINGS", variant: "neutral" },

  // AUDIT maintenance
  "audit.retention.purged":    { label: "Audit purged", category: "AUDIT", variant: "warning" },

  // AVATAR
  "avatar.uploaded":           { label: "Avatar uploaded", category: "AVATARS", variant: "success" },

  // SETUP
  "setup.finished":            { label: "Setup finished", category: "SETUP", variant: "success" },
};

const categoryFallbackVariant: Record<string, ActionInfo["variant"]> = {
  AUTH: "neutral",
  RBAC: "neutral",
  EVIDENCE: "neutral",
  EXPORTS: "neutral",
  SETTINGS: "neutral",
  AUDIT: "neutral",
  AVATARS: "neutral",
  SETUP: "neutral",
};

/** Map an action string to a label entry. */
export function actionInfo(action: string, category?: string): ActionInfo {
  const exact = byAction[action];
  if (exact) return exact;

  // Pattern helpers
  if (action.startsWith("rbac.deny.")) {
    return { label: "Denied: RBAC", category: "RBAC", variant: "danger" };
  }
  if (action.startsWith("rbac.user_role.")) {
    return { label: "User role changed", category: "RBAC", variant: "neutral" };
  }
  if (action.startsWith("export.")) {
    return { label: "Export event", category: "EXPORTS", variant: "neutral" };
  }
  if (action.startsWith("evidence.")) {
    return { label: "Evidence event", category: "EVIDENCE", variant: "neutral" };
  }
  if (action.startsWith("auth.")) {
    return { label: "Auth event", category: "AUTH", variant: "neutral" };
  }
  if (action.startsWith("settings.")) {
    return { label: "Settings event", category: "SETTINGS", variant: "neutral" };
  }
  if (action.startsWith("audit.")) {
    return { label: "Audit event", category: "AUDIT", variant: "neutral" };
  }
  if (action.startsWith("avatar.")) {
    return { label: "Avatar event", category: "AVATARS", variant: "neutral" };
  }
  if (action.startsWith("setup.")) {
    return { label: "Setup event", category: "SETUP", variant: "neutral" };
  }

  const cat = (category || "OTHER").toUpperCase();
  return {
    label: humanize(action),
    category: cat,
    variant: categoryFallbackVariant[cat] ?? "neutral",
  };
}

/** Format unknown action codes to a readable label. */
export function humanize(code: string): string {
  return code
    .replace(/\./g, " ")
    .replace(/_/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .replace(/^\w/, (m) => m.toUpperCase());
}

/** Optional helper to render an accessible label string. */
export function renderActionLabel(action: string, category?: string): string {
  const info = actionInfo(action, category);
  return `${info.label} (${action})`;
}
