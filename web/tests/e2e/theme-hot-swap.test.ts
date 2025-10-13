import { test, expect } from "@playwright/test";

const pages = [
  "dashboard",
  "admin/settings/theming",
  "admin/settings/branding",
  "admin/users",
];

const themes = [
  { slug: "flatly", mode: "light" },
  { slug: "darkly", mode: "dark" },
  { slug: "cerulean", mode: "light" },
  { slug: "slate", mode: "dark" },
];

const navSelector = "header nav.navbar";

async function selectTheme(page, themeSlug: string, mode: "light" | "dark") {
  await page.goto("/admin/settings/theming");
  await page.waitForSelector("select#themeSelect");
  await page.selectOption("select#themeSelect", themeSlug);

  const modeRadio = await page.$(`input[name="themeMode"][value="${mode}"]`);
  if (modeRadio) {
    if (!(await modeRadio.evaluate((el) => (el as HTMLInputElement).checked))) {
      await modeRadio.check();
    }
  }

  await page.click("button:has-text('Save')");
  await page.waitForSelector(navSelector);

  await page.waitForTimeout(200);
}

test.describe("Theme hot-swap", () => {
  test.use({ storageState: "./tests/e2e/.auth/admin.json" });

  for (const { slug, mode } of themes) {
    test(`applies ${slug} (${mode}) without refresh`, async ({ page }) => {
      await selectTheme(page, slug, mode);

      await page.waitForSelector(navSelector, { state: "visible" });

      const href = await page.evaluate(() => {
        const link = document.getElementById("phpgrc-theme-css") as HTMLLinkElement | null;
        return link?.href ?? null;
      });

      expect(href).toBeTruthy();

      const dataTheme = await page.evaluate(() => document.documentElement.getAttribute("data-theme"));
      const dataMode = await page.evaluate(() => document.documentElement.getAttribute("data-mode"));
      const dataVariant = await page.evaluate(() => document.documentElement.getAttribute("data-theme-variant"));

      expect(dataTheme).toBe(slug);
      expect(dataMode).toBe(mode);
      expect(dataVariant).not.toBeNull();

      const navbarClass = await page.locator(navSelector).getAttribute("class");
      expect(navbarClass).toBeTruthy();

      for (const route of pages) {
        await page.goto(`/${route}`);
        await page.waitForSelector(navSelector);
        const themeAttr = await page.evaluate(() => document.documentElement.getAttribute("data-theme"));
        expect(themeAttr).toBe(slug);
      }
    });
  }
});
