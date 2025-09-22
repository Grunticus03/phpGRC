import { describe, it, beforeEach, afterEach, expect, vi } from "vitest";
import { render, screen, cleanup } from "@testing-library/react";
import React from "react";
import AuditIndex from "../index";

const payload = {
  ok: true,
  items: [
    {
      id: "01ABC",
      occurred_at: "2025-09-20T00:00:00Z",
      actor_id: null,
      action: "rbac.deny.capability",
      category: "RBAC",
      entity_type: "rbac.policy",
      entity_id: "core.metrics.view",
      ip: "127.0.0.1",
      ua: "tests",
      meta: null,
    },
  ],
  nextCursor: null,
};

describe("AuditIndex action labels", () => {
  beforeEach(() => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => ({
        ok: true,
        status: 200,
        json: async () => payload,
      })) as unknown as typeof fetch
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    cleanup();
  });

  it("renders human label and aria text for action", async () => {
    render(<AuditIndex />);
    const cell = await screen.findByText("Access denied: capability disabled");
    expect(cell).toBeTruthy();
    expect(cell).toHaveAttribute("aria-label", "Access denied because the requested capability is disabled");
    expect(cell).toHaveAttribute("title", "rbac.deny.capability");
  });
});
