import { describe, it, expect } from "vitest";
import { actionInfo, actionA11yLabel, __ACTION_MAP } from "./actionInfo";

describe("actionInfo", () => {
  it("returns canonical entries", () => {
    expect(actionInfo("rbac.deny.policy").label).toMatch(/policy/i);
    expect(actionInfo("rbac.deny.capability").label).toMatch(/capability/i);
    expect(actionInfo("rbac.deny.unauthenticated").label).toMatch(/unauthenticated/i);
    expect(actionInfo("rbac.deny.role_mismatch").label).toMatch(/role mismatch/i);
  });

  it("maps seeded test variant rbac.deny.role", () => {
    const info = actionInfo("rbac.deny.role");
    expect(info.label).toMatch(/role mismatch/i);
    expect(info.category).toBe("RBAC");
  });

  it("handles rbac.deny.policy.<name> prefix", () => {
    const info = actionInfo("rbac.deny.policy.core.metrics.view");
    expect(info.label).toMatch(/metrics view/i);
    expect(info.aria).toMatch(/core\.metrics\.view/);
  });

  it("handles rbac.deny.role.* prefix", () => {
    const info = actionInfo("rbac.deny.role.required");
    expect(info.label).toMatch(/role mismatch/i);
  });

  it("falls back safely", () => {
    const info = actionInfo("weird.string");
    expect(info.label).toMatch(/weird\.string/);
    expect(info.category).toBe("RBAC");
  });

  it("a11y helper mirrors aria", () => {
    const key = "rbac.deny.unauthorized";
    expect(actionA11yLabel(key)).toBe(actionInfo(key).aria);
  });

  it("exports a frozen readonly map", () => {
    expect(Object.isFrozen(__ACTION_MAP)).toBe(true);
    expect(typeof __ACTION_MAP["rbac.deny.policy"]).toBe("object");
  });
});
