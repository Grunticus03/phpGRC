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
    const preview = container.querySelector(".theme-designer-preview") as HTMLElement;
    expect(preview).not.toBeNull();

    const defaultOpen = navigation.querySelector(".theme-designer-menu-item--open .theme-designer-menu-button");
    expect(defaultOpen).not.toBeNull();
    expect((defaultOpen as HTMLButtonElement).textContent).toBe("All");

    const openSettings = async (feature: string): Promise<HTMLElement> => {
      const trigger = Array.from(
        navigation.querySelectorAll(
          ".theme-designer-menu-list > li .theme-designer-menu-button"
        )
      ).find((button) => (button as HTMLButtonElement).textContent === feature) as HTMLButtonElement | undefined;
      expect(trigger).toBeDefined();

      const currentlyOpen = navigation.querySelector(
        ".theme-designer-menu-item--open .theme-designer-menu-button"
      ) as HTMLButtonElement | null;

      if (currentlyOpen?.textContent !== feature) {
        fireEvent.focus(trigger as HTMLButtonElement);
      }

      let dropdownPanel: HTMLElement | null = null;
      await waitFor(() => {
        const panel = document.querySelector(
          ".theme-designer-dropdown-panel"
        ) as HTMLElement | null;
        if (!panel) {
          throw new Error("Dropdown panel not ready");
        }
        dropdownPanel = panel;
      });

      const columns = dropdownPanel!.querySelectorAll(".theme-designer-dropdown-column");
      const contextColumn = columns[0] as HTMLElement;
      const variantColumn = columns[1] as HTMLElement;

      const lightContext = within(contextColumn).getByRole("button", { name: /^Light$/ });
      fireEvent.mouseEnter(lightContext);
      fireEvent.focus(lightContext);
      await waitFor(() => expect(lightContext.classList.contains("is-active")).toBe(true));

      const primaryVariant = within(variantColumn).getByRole("button", { name: "Primary" });
      fireEvent.mouseEnter(primaryVariant);
      fireEvent.focus(primaryVariant);
      await waitFor(() => expect(primaryVariant.classList.contains("is-active")).toBe(true));

      const settingsColumn = dropdownPanel!.querySelector(".theme-designer-dropdown-column.settings") as HTMLElement;
      expect(settingsColumn).not.toBeNull();
      return settingsColumn;
    };

    const allSettings = await openSettings("All");
    const backgroundInput = within(allSettings).getByLabelText("Background") as HTMLInputElement;
    fireEvent.input(backgroundInput, { target: { value: "#123456" } });

    expect(preview.style.getPropertyValue("--td-buttons-light-primary-background")).toBe("#123456");
    expect(preview.style.getPropertyValue("--td-buttons-dark-primary-background")).toBe("#0d6efd");
    expect(preview.style.getPropertyValue("--td-alerts-light-primary-background")).toBe("#123456");

    fireEvent.input(backgroundInput, { target: { value: "#abcdef" } });

    expect(preview.style.getPropertyValue("--td-buttons-light-primary-background")).toBe("#abcdef");
    expect(preview.style.getPropertyValue("--td-alerts-light-primary-background")).toBe("#abcdef");
    expect(preview.style.getPropertyValue("--td-buttons-dark-primary-background")).toBe("#0d6efd");

    const buttonSettings = await openSettings("Buttons");
    const buttonWeightSelect = within(buttonSettings).getByLabelText("Weight") as HTMLSelectElement;
    fireEvent.change(buttonWeightSelect, { target: { value: "700" } });
    expect(preview.style.getPropertyValue("--td-buttons-light-primary-font-weight")).toBe("700");

    const underlineSetting = within(buttonSettings).getByText("Underline").closest(".theme-designer-setting") as HTMLElement;
    const underlineSwitch = underlineSetting.querySelector('input[type="checkbox"]') as HTMLInputElement;
    fireEvent.click(underlineSwitch);
    expect(preview.style.getPropertyValue("--td-buttons-light-primary-text-decoration")).toBe("underline");

    const tableSettings = await openSettings("Tables");
    const tableWeightSelect = within(tableSettings).getByLabelText("Weight") as HTMLSelectElement;
    fireEvent.change(tableWeightSelect, { target: { value: "500" } });
    expect(preview.style.getPropertyValue("--td-tables-light-primary-font-weight")).toBe("500");

    const pillSettings = await openSettings("Pills");
    const pillWeightSelect = within(pillSettings).getByLabelText("Weight") as HTMLSelectElement;
    fireEvent.change(pillWeightSelect, { target: { value: "600" } });
    expect(preview.style.getPropertyValue("--td-pills-light-primary-font-weight")).toBe("600");

    const alertSettings = await openSettings("Alerts");
    const alertWeightSelect = within(alertSettings).getByLabelText("Weight") as HTMLSelectElement;
    fireEvent.change(alertWeightSelect, { target: { value: "300" } });
    expect(preview.style.getPropertyValue("--td-alerts-light-primary-font-weight")).toBe("300");
  });

  it("renders feature previews similar to Bootswatch sample", () => {
    render(<ThemeDesigner />);

    expect(screen.getByRole("heading", { name: "Theme Designer" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "Navbars" })).toBeVisible();
    expect(screen.getAllByRole("navigation")).not.toHaveLength(0);
    expect(document.querySelector("table")).not.toBeNull();
  });

  it("surfaces theme management modals from the Theme menu", async () => {
    render(<ThemeDesigner />);

    const themeButton = screen.getByRole("button", { name: "Theme" });
    fireEvent.click(themeButton);

    const saveAction = screen.getByRole("button", { name: "Save…" });
    expect(screen.getByRole("button", { name: "Load…" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Delete…" })).toBeDisabled();

    fireEvent.click(saveAction);

    const saveModal = await screen.findByRole("dialog", { name: "Save Theme" });
    const nameInput = within(saveModal).getByLabelText("Theme name");

    const saveConfirm = within(saveModal).getByRole("button", { name: "Save" });
    fireEvent.click(saveConfirm);
    expect(within(saveModal).getByText("Enter a theme name.")).toBeVisible();

    fireEvent.change(nameInput, { target: { value: "Slate" } });
    fireEvent.click(saveConfirm);
    expect(within(saveModal).getByText("Theme name conflicts with a built-in theme.")).toBeVisible();

    const cancelButton = within(saveModal).getByRole("button", { name: "Cancel" });
    fireEvent.click(cancelButton);
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Save Theme" })).toBeNull());

    fireEvent.click(themeButton);
    expect(screen.getByRole("button", { name: "Delete…" })).toBeDisabled();
  });
});
