/** @vitest-environment jsdom */
import { describe, it, expect } from "vitest";
import { actionInfo } from "../auditLabels";

describe("auditLabels RBAC denies", () => {
  it("maps explicit deny actions to danger variant", () => {
    const actions = [
      "rbac.deny.unauthenticated",
      "rbac.deny.role_mismatch",
      "rbac.deny.policy",
      "rbac.deny.capability",
      "rbac.deny.unknown_policy",
    ];
    actions.forEach((a) => {
      const info = actionInfo(a, "RBAC");
      expect(info.category).toBe("RBAC");
      expect(info.variant).toBe("danger");
      expect(info.label.toLowerCase()).toMatch(/^denied/);
    });
  });

  it("falls back for unknown rbac.deny.* with danger variant", () => {
    const info = actionInfo("rbac.deny.custom_reason", "RBAC");
    expect(info.category).toBe("RBAC");
    expect(info.variant).toBe("danger");
    expect(info.label.toLowerCase()).toContain("denied");
  });
});
