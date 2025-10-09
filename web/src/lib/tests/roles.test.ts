import { describe, expect, test } from "vitest";
import { canonicalRoleId, roleLabelFromId, roleOptionsFromList, roleIdsFromNames } from "../roles";

describe("roles helper", () => {
  test("canonicalRoleId produces slug-friendly ids", () => {
    expect(canonicalRoleId("Admin")).toBe("admin");
    expect(canonicalRoleId("Risk Manager")).toBe("risk_manager");
    expect(canonicalRoleId("Compliance-Lead")).toBe("compliance_lead");
    expect(canonicalRoleId("  üser manager  ")).toBe("user_manager");
  });

  test("roleLabelFromId turns ids into readable labels", () => {
    expect(roleLabelFromId("admin")).toBe("Admin");
    expect(roleLabelFromId("risk_manager")).toBe("Risk Manager");
    expect(roleLabelFromId("role_auditor")).toBe("Role Auditor");
  });

  test("roleOptionsFromList deduplicates and formats", () => {
    const options = roleOptionsFromList(["Admin", "Risk Manager", "admin", "risk_manager"]);
    expect(options).toEqual([
      { id: "admin", name: "Admin" },
      { id: "risk_manager", name: "Risk Manager" },
    ]);
  });

  test("roleOptionsFromList accepts object entries", () => {
    const options = roleOptionsFromList([
      { id: "role_admin", name: "Admin" },
      { name: "Risk Manager" },
      { id: "role_admin", name: "Duplicate" },
    ]);
    expect(options).toEqual([
      { id: "role_admin", name: "Admin" },
      { id: "risk_manager", name: "Risk Manager" },
    ]);
  });

  test("roleOptionsFromList coerces numeric entries", () => {
    const options = roleOptionsFromList([123, { id: 456, name: 789 }]);
    expect(options).toEqual([
      { id: "123", name: "123" },
      { id: "456", name: "789" },
    ]);
  });

  test("roleIdsFromNames normalizes arbitrary strings", () => {
    expect(roleIdsFromNames(["Admin", "Risk Manager", "Admin"]))
      .toEqual(["admin", "risk_manager"]);
  });

  test("roleIdsFromNames accepts numeric values", () => {
    expect(roleIdsFromNames(["Admin", 123, "ADMIN"])).toEqual(["admin", "123"]);
  });
});
