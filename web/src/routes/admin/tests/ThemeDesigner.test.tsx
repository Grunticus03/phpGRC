/** @vitest-environment jsdom */
import { describe, expect, it, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, within, waitFor } from "@testing-library/react";
import React from "react";
import ThemeDesigner from "../ThemeDesigner";

describe("ThemeDesigner", () => {
  let originalMatchMedia: typeof window.matchMedia;

  beforeEach(() => {
    originalMatchMedia = window.matchMedia;
    window.matchMedia = (() => ({
      matches: false,
      media: "",
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    })) as unknown as typeof window.matchMedia;
  });

  afterEach(() => {
    window.matchMedia = originalMatchMedia;
  });

  it("opens multilevel menus and updates variables for All > Light > Primary", async () => {
    const { container } = render(<ThemeDesigner />);

    const navigation = screen.getByRole("navigation", { name: "Theme designer controls" });
    const allTrigger = within(navigation).getAllByRole("button", { name: "All" })[0];
    fireEvent.mouseEnter(allTrigger);

    const contextList = document.querySelector(
      ".theme-designer-dropdown-column .theme-designer-dropdown-list"
    ) as HTMLElement | null;
    expect(contextList).not.toBeNull();
    const lightContext = within(contextList as HTMLElement).getByRole("button", { name: /^Light$/ });
    fireEvent.focus(lightContext);
    await waitFor(() => expect(lightContext.classList.contains("is-active")).toBe(true));

    const dropdownPanel = document.querySelector(
      ".theme-designer-dropdown-panel"
    ) as HTMLElement | null;
    expect(dropdownPanel).not.toBeNull();
    const variantColumn = dropdownPanel!
      .querySelectorAll(".theme-designer-dropdown-column")[1] as HTMLElement | undefined;
    expect(variantColumn).toBeDefined();
    const primaryVariant = within(variantColumn as HTMLElement).getByRole("button", { name: "Primary" });
    fireEvent.focus(primaryVariant);
    await waitFor(() => expect(primaryVariant.classList.contains("is-active")).toBe(true));

    const settingsColumn = document.querySelector(
      ".theme-designer-dropdown-column.settings"
    ) as HTMLElement;
    const backgroundInputs = within(settingsColumn).getAllByLabelText(/Background/i) as HTMLInputElement[];
    const backgroundColorInput = backgroundInputs.find((input) => input.type === "color");
    expect(backgroundColorInput).toBeDefined();
    fireEvent.input(backgroundColorInput as HTMLInputElement, { target: { value: "#123456" } });

    const preview = container.querySelector(".theme-designer-preview") as HTMLElement;
    expect(preview.style.getPropertyValue("--td-buttons-light-primary-background")).toBe("#123456");
    expect(preview.style.getPropertyValue("--td-buttons-dark-primary-background")).toBe("#0d6efd");
    expect(preview.style.getPropertyValue("--td-alerts-light-primary-background")).toBe("#123456");

    fireEvent.input(backgroundColorInput as HTMLInputElement, { target: { value: "#abcdef" } });

    expect(preview.style.getPropertyValue("--td-buttons-light-primary-background")).toBe("#abcdef");
    expect(preview.style.getPropertyValue("--td-alerts-light-primary-background")).toBe("#abcdef");
    expect(preview.style.getPropertyValue("--td-buttons-dark-primary-background")).toBe("#0d6efd");
  });

  it("renders feature previews similar to Bootswatch sample", () => {
    render(<ThemeDesigner />);

    expect(screen.getByRole("heading", { name: "Theme Designer" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "Navbars" })).toBeVisible();
    expect(screen.getAllByRole("navigation")).not.toHaveLength(0);
    expect(document.querySelector("table")).not.toBeNull();
  });
});
