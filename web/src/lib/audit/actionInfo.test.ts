// FILE: web/src/lib/audit/actionInfo.test.ts
import { describe, it, expect } from "vitest";
import { actionInfo, actionA11yLabel, __ACTION_MAP } from "./actionInfo";

describe("actionInfo", () => {
  it("returns explicit entries for known denies", () => {
    expect(actionInfo("rbac.deny.require_auth").label).toContain("authentication required");
    expect(actionInfo("rbac.deny.unauthorized").label).toContain("unauthorized");
    expect(actionInfo("rbac.deny.policy.core.metrics.view").label).toContain("metrics view policy");
  });

  it("handles generic policy prefix", () => {
    const info = actionInfo("rbac.deny.policy.core.settings.update");
    expect(info.label).toContain("core.settings.update");
    expect(info.aria).toContain("core.settings.update");
    expect(info.category).toBe("RBAC");
  });

  it("falls back safely for unknown variants", () => {
    const info = actionInfo("rbac.deny.something_new");
    expect(info.label).toContain("rbac.deny.something_new");
    expect(info.category).toBe("RBAC");
  });

  it("provides an a11y helper", () => {
    expect(actionA11yLabel("rbac.deny.role_required")).toContain("required role");
  });

  it("exposes a frozen map for reference", () => {
    expect(Object.isFrozen(__ACTION_MAP)).toBe(true);
  });
});
