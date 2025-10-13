import { expect, test } from '@playwright/test';

const PLACEHOLDER_PNG =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAn8B9nSTaQAAAABJRU5ErkJggg==';

test.beforeEach(async ({ page }, testInfo) => {
  const theme = (testInfo.project.metadata?.theme as string) ?? 'slate';

  await page.addInitScript((preferredTheme) => {
    try {
      localStorage.setItem('phpgrc.ui.theme', preferredTheme);
    } catch {
      // ignore write failures in beforeEach
    }
  }, theme);

  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());

    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { 'access-control-allow-origin': '*' } });
      return;
    }

    if (url.pathname.startsWith('/api/images/')) {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'image/png' },
        body: Buffer.from(PLACEHOLDER_PNG, 'base64'),
      });
      return;
    }

    if (url.pathname === '/api/health/fingerprint') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          ok: true,
          fingerprint: 'playwright',
          overlay: {
            loaded: false,
            path: null,
            mtime: null,
          },
          summary: {
            rbac: { enabled: false, require_auth: false, roles_count: 0 },
            audit: { enabled: true, retention_days: 30 },
            evidence: { enabled: true },
            avatars: { enabled: true, size_px: 128, format: 'webp' },
            api_throttle: {
              enabled: false,
              strategy: 'user',
              window_seconds: 60,
              max_requests: 60,
            },
          },
        }),
      });
      return;
    }

    if (url.pathname === '/api/dashboard/kpis') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          ok: true,
          kpis: [],
          meta: {
            generated_at: new Date().toISOString(),
            window: {},
          },
        }),
      });
      return;
    }

    if (url.pathname === '/api/settings/ui' || url.pathname === '/settings/ui') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          ok: true,
          config: {
            ui: {
              theme: {
                default: theme,
                allow_user_override: true,
                force_global: false,
                overrides: {},
              },
              nav: {
                sidebar: {
                  default_order: ['dashboard', 'evidence', 'metrics'],
                },
              },
              brand: {
                title_text: 'phpGRC — Playwright',
                favicon_asset_id: null,
                primary_logo_asset_id: null,
                secondary_logo_asset_id: null,
                header_logo_asset_id: null,
                footer_logo_asset_id: null,
                footer_logo_disabled: false,
              },
            },
          },
          etag: 'W/"ui:playwright"',
        }),
      });
      return;
    }

    if (url.pathname === '/api/me/prefs/ui' || url.pathname === '/me/prefs/ui') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          ok: true,
          etag: 'W/"prefs:playwright"',
          prefs: {
            theme,
            mode: null,
            overrides: {},
            sidebar: {
              collapsed: false,
              width: 256,
              order: ['dashboard', 'evidence', 'metrics'],
            },
          },
        }),
      });
      return;
    }

    if (url.pathname === '/api/settings/ui/themes' || url.pathname === '/settings/ui/themes') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          version: '5.3.8',
          themes: [
            { slug: 'slate', name: 'Slate', variants: ['light', 'dark'] },
            { slug: 'flatly', name: 'Flatly', variants: ['light'] },
            { slug: 'darkly', name: 'Darkly', variants: ['dark'] },
          ],
        }),
      });
      return;
    }

    if (route.request().method() === 'PUT') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: route.request().postData() ?? JSON.stringify({ ok: true }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ ok: true }),
    });
  });
});

test('app shell renders without console errors', async ({ page }, testInfo) => {
  const consoleErrors: string[] = [];

  page.on('pageerror', (error) => {
    consoleErrors.push(error.message);
  });

  page.on('console', (message) => {
    if (message.type() === 'error') {
      consoleErrors.push(message.text());
    }
  });

  await page.goto('/');
  await page.waitForLoadState('networkidle');

  const appRoot = page.locator('#root');
  await expect(appRoot).toBeVisible();

  const theme = (testInfo.project.metadata?.theme as string) ?? 'slate';
  await expect(page).toHaveScreenshot(`layout-${theme}.png`, {
    animations: 'disabled',
    fullPage: true,
  });

  expect(consoleErrors).toEqual([]);
});
