/* FILE: web/src/lib/audit/actionInfo.test.ts */
import { describe, it, expect } from "vitest";
import { actionInfo, actionA11yLabel, __ACTION_MAP } from "./actionInfo";

describe("actionInfo map", () => {
  const pairs: Array<[string, { label: string; ariaIncludes: string }]> = [
    ["rbac.deny.capability", { label: "Access denied: capability disabled", ariaIncludes: "capability is disabled" }],
    ["rbac.deny.unauthenticated", { label: "Access denied: unauthenticated", ariaIncludes: "not authenticated" }],
    ["rbac.deny.role_mismatch", { label: "Access denied: role mismatch", ariaIncludes: "required role" }],
    ["rbac.deny.policy", { label: "Access denied: policy check failed", ariaIncludes: "required policy" }],
    // synonyms
    ["rbac.deny.require_auth", { label: "Access denied: authentication required", ariaIncludes: "authentication is required" }],
    ["rbac.deny.unauthorized", { label: "Access denied: unauthorized", ariaIncludes: "lacks permission" }],
    ["rbac.deny.role_required", { label: "Access denied: role required", ariaIncludes: "required role" }],
    ["rbac.deny.role_missing", { label: "Access denied: role missing", ariaIncludes: "required role" }],
    // explicit policy keys
    ["rbac.deny.policy.core.metrics.view", { label: "Access denied: metrics view policy", ariaIncludes: "core.metrics.view" }],
    ["rbac.deny.policy.core.audit.view", { label: "Access denied: audit view policy", ariaIncludes: "core.audit.view" }],
    ["rbac.deny.policy.core.evidence.view", { label: "Access denied: evidence view policy", ariaIncludes: "core.evidence.view" }],
  ];

  it.each(pairs)("maps %s", (key, expected) => {
    const info = actionInfo(key);
    expect(info.label).toBe(expected.label);
    expect(info.aria).toContain(expected.ariaIncludes);
    expect(info.category).toBe("RBAC");
  });

  it("prefix mapping for arbitrary policy names", () => {
    const key = "rbac.deny.policy.some.custom.policy";
    const info = actionInfo(key);
    expect(info.label).toBe("Access denied: some.custom.policy policy");
    expect(info.aria).toContain("policy some.custom.policy");
  });

  it("fallback for unknown actions", () => {
    const key = "rbac.deny.unknown.variant";
    const info = actionInfo(key);
    expect(info.label).toBe(`Access denied: ${key}`);
    expect(info.aria).toBe(`Access denied due to ${key}`);
  });

  it("trims whitespace", () => {
    const info = actionInfo("  rbac.deny.capability  ");
    expect(info.label).toBe("Access denied: capability disabled");
  });

  it("actionA11yLabel mirrors aria", () => {
    const key = "rbac.deny.role_mismatch";
    expect(actionA11yLabel(key)).toBe(actionInfo(key).aria);
  });

  it("exports a frozen map snapshot", () => {
    expect(Object.isFrozen(__ACTION_MAP)).toBe(true);
    // ensure no own-property '__proto__' (avoid proto pollution), but it will exist via prototype
    expect(Object.prototype.hasOwnProperty.call(__ACTION_MAP, "__proto__")).toBe(false);
    expect(__ACTION_MAP["rbac.deny.capability"].label).toBe("Access denied: capability disabled");
  });
});
