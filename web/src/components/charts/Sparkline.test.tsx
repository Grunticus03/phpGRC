/* FILE: web/src/components/charts/Sparkline.test.tsx */
import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import React from "react";
import Sparkline from "./Sparkline";

describe("Sparkline", () => {
  it("renders empty svg on no values", () => {
    render(<Sparkline values={[]} ariaLabel="spark" />);
    const svg = screen.getByLabelText("spark");
    expect(svg.tagName.toLowerCase()).toBe("svg");
    expect(svg.getAttribute("data-empty")).toBe("true");
  });

  it("renders a path for values", () => {
    render(<Sparkline values={[0, 0.5, 1]} ariaLabel="spark2" />);
    const svg = screen.getByLabelText("spark2");
    const path = svg.querySelector("path");
    expect(path).not.toBeNull();
    expect(path?.getAttribute("d") ?? "").toContain("L");
  });
});
