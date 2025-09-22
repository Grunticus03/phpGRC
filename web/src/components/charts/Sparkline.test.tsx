import { describe, it, expect } from "vitest";
import { render } from "@testing-library/react";
import { Sparkline } from "./Sparkline";

describe("Sparkline", () => {
  it("renders svg with path and last-point marker", () => {
    const { container } = render(
      <Sparkline
        data={[
          { x: "2025-09-18", y: 0 },
          { x: "2025-09-19", y: 2 },
          { x: "2025-09-20", y: 1 },
        ]}
      />
    );
    const svg = container.querySelector("svg");
    const path = container.querySelector("path");
    const circle = container.querySelector("circle");
    expect(svg).toBeTruthy();
    expect(path).toBeTruthy();
    expect(circle).toBeTruthy();
  });
});
