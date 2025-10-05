export type ActionInfo = {
  label: string;
  aria: string;
  category: "RBAC";
};

/**
 * Canonical RBAC deny variants and common synonyms.
 * Keep labels short and consistent. Aria uses full sentences.
 */
const MAP: Record<string, ActionInfo> = {
  // Canonical
  "rbac.deny.capability": {
    label: "Access denied: capability disabled",
    aria: "Access denied because the requested capability is disabled",
    category: "RBAC",
  },
  "rbac.deny.unauthenticated": {
    label: "Access denied: unauthenticated",
    aria: "Access denied because the user is not authenticated",
    category: "RBAC",
  },
  "rbac.deny.role_mismatch": {
    label: "Access denied: role mismatch",
    aria: "Access denied because a required role is missing or does not match",
    category: "RBAC",
  },
  "rbac.deny.policy": {
    label: "Access denied: policy check failed",
    aria: "Access denied because a required policy is not satisfied",
    category: "RBAC",
  },

  // Synonyms kept for compatibility
  "rbac.deny.require_auth": {
    label: "Access denied: authentication required",
    aria: "Access denied because authentication is required",
    category: "RBAC",
  },
  "rbac.deny.unauthorized": {
    label: "Access denied: unauthorized",
    aria: "Access denied because the user lacks permission",
    category: "RBAC",
  },
  "rbac.deny.role_required": {
    label: "Access denied: role required",
    aria: "Access denied because a required role is missing",
    category: "RBAC",
  },
  "rbac.deny.role_missing": {
    label: "Access denied: role missing",
    aria: "Access denied because a required role is missing",
    category: "RBAC",
  },
  // Seeded test variant
  "rbac.deny.role": {
    label: "Access denied: role mismatch",
    aria: "Access denied because a required role is missing or does not match",
    category: "RBAC",
  },

  // Explicit policy names we know are emitted frequently
  "rbac.deny.policy.core.metrics.view": {
    label: "Access denied: metrics view policy",
    aria: "Access denied because policy core.metrics.view is not satisfied",
    category: "RBAC",
  },
  "rbac.deny.policy.core.audit.view": {
    label: "Access denied: audit view policy",
    aria: "Access denied because policy core.audit.view is not satisfied",
    category: "RBAC",
  },
  "rbac.deny.policy.core.evidence.view": {
    label: "Access denied: evidence view policy",
    aria: "Access denied because policy core.evidence.view is not satisfied",
    category: "RBAC",
  },
};

function fallback(action: string): ActionInfo {
  const key = String(action || "").trim();
  return {
    label: `Access denied: ${key}`,
    aria: `Access denied due to ${key}`,
    category: "RBAC",
  };
}

/**
 * Return a user-facing label and aria description for an audit action.
 * Covers explicit rbac.deny.* variants with a safe fallback.
 */
export function actionInfo(action: string): ActionInfo {
  const key = String(action || "").trim();
  if (key in MAP) return MAP[key];

  // Prefix mapping for rbac.deny.policy.<name>
  if (key.startsWith("rbac.deny.policy.")) {
    const policy = key.slice("rbac.deny.policy.".length);
    return {
      label: `Access denied: ${policy} policy`,
      aria: `Access denied because policy ${policy} is not satisfied`,
      category: "RBAC",
    };
  }

  // Generic prefix mapping for rbac.deny.role.*
  if (key.startsWith("rbac.deny.role.")) {
    return MAP["rbac.deny.role"];
  }

  return fallback(key);
}

/** Accessibility helper for screen readers. */
export function actionA11yLabel(action: string): string {
  return actionInfo(action).aria;
}

// Export map for snapshot/testing (read-only)
export const __ACTION_MAP = Object.freeze({ ...MAP });
