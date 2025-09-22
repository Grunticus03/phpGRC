// FILE: web/src/lib/audit/actionInfo.ts
export type ActionInfo = {
  label: string;
  aria: string;
  category: "RBAC";
};

const MAP: Record<string, ActionInfo> = {
  "rbac.deny.require_auth": {
    label: "Access denied: authentication required",
    aria: "Access denied because authentication is required",
    category: "RBAC",
  },
  "rbac.deny.unauthenticated": {
    label: "Access denied: unauthenticated",
    aria: "Access denied because the user is not authenticated",
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
  // Policy-specific denies: enumerate common policies explicitly
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
  // Generic policy deny
  "rbac.deny.policy": {
    label: "Access denied: policy check failed",
    aria: "Access denied because a required policy is not satisfied",
    category: "RBAC",
  },
};

function fallback(action: string): ActionInfo {
  return {
    label: `Access denied: ${action}`,
    aria: `Access denied due to ${action}`,
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
  // Try prefix-specific mapping for rbac.deny.policy.<name>
  if (key.startsWith("rbac.deny.policy.")) {
    const policy = key.slice("rbac.deny.policy.".length);
    return {
      label: `Access denied: ${policy} policy`,
      aria: `Access denied because policy ${policy} is not satisfied`,
      category: "RBAC",
    };
  }
  return fallback(key);
}

/**
 * Accessibility helper for screen readers.
 */
export function actionA11yLabel(action: string): string {
  return actionInfo(action).aria;
}

// Export map for snapshot/testing (read-only)
export const __ACTION_MAP = Object.freeze({ ...MAP });
