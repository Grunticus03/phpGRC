import { expect, test, type Page, type TestInfo } from '@playwright/test';

const PLACEHOLDER_PNG =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAn8B9nSTaQAAAABJRU5ErkJggg==';

type ThemeMetadata = { theme?: string };

function resolveTheme(testInfo: TestInfo): string {
  const useMetadata = (testInfo.project.use as { metadata?: ThemeMetadata })?.metadata?.theme;
  if (typeof useMetadata === 'string' && useMetadata.length > 0) {
    return useMetadata;
  }

  const projectMetadata = (testInfo.project.metadata as ThemeMetadata | undefined)?.theme;
  if (typeof projectMetadata === 'string' && projectMetadata.length > 0) {
    return projectMetadata;
  }

  return 'slate';
}

const pages = ['dashboard', 'admin/settings/theming', 'admin/settings/branding', 'admin/users'];

const themes = [
  { slug: 'flatly', mode: 'light' },
  { slug: 'darkly', mode: 'dark' },
  { slug: 'cerulean', mode: 'light' },
  { slug: 'slate', mode: 'dark' },
];

const navSelector = 'header nav.navbar';

async function selectTheme(page: Page, themeSlug: string, mode: 'light' | 'dark') {
  const initialSettingsLoad = page.waitForResponse(
    (response) => response.request().method() === 'GET' && response.url().endsWith('/api/settings/ui')
  );
  await page.goto('/admin/settings/theming');
  await page.waitForSelector('select#themeSelect');
  await initialSettingsLoad;
  const toastDismiss = page.locator('button.btn-close');
  if (await toastDismiss.count()) {
    await toastDismiss.first().click();
  }
  const themeSelect = page.locator('select#themeSelect');
  await themeSelect.selectOption(themeSlug);
  await expect(themeSelect).toHaveValue(themeSlug);

  const modeRadio = page.locator(`input[name="themeMode"][value="${mode}"]`);
  if (!(await modeRadio.isDisabled()) && !(await modeRadio.isChecked())) {
    await modeRadio.check();
  }

  await page.evaluate(
    async ({ nextTheme, nextMode }) => {
      const root = document.documentElement;
      root.setAttribute('data-theme', nextTheme);
      root.setAttribute('data-mode', nextMode);
      root.setAttribute('data-bs-theme', nextMode);
      root.setAttribute('data-theme-variant', `${nextTheme}-${nextMode}`);
      try {
        localStorage.setItem('phpgrc.ui.theme', nextTheme);
      } catch {
        // ignore localStorage failures
      }
      await fetch('/_playwright/theme', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ theme: nextTheme, mode: nextMode }),
      });
    },
    { nextTheme: themeSlug, nextMode: mode }
  );

  await expect(page.locator('html')).toHaveAttribute('data-theme', themeSlug);
  await expect(page.locator('html')).toHaveAttribute('data-mode', mode);

  for (const route of pages) {
    await page.goto(`/${route}`);
    await page.waitForSelector(navSelector);
    await expect(page.locator('html')).toHaveAttribute('data-theme', themeSlug);
  }
}

const DEFAULT_SIDEBAR_ORDER = ['dashboard', 'evidence', 'metrics'] as const;
const NO_CACHE_HEADERS = {
  'cache-control': 'no-store, max-age=0',
  pragma: 'no-cache',
};

type ThemeMode = 'light' | 'dark';

function initialModeFor(themeSlug: string): ThemeMode {
  if (themeSlug === 'darkly' || themeSlug === 'slate') {
    return 'dark';
  }
  return 'light';
}

function buildUiConfig(theme: string, mode: ThemeMode) {
  return {
    theme: {
      default: theme,
      mode,
      allow_user_override: true,
      force_global: false,
      overrides: {},
      designer: {
        storage: 'local',
        filesystem_path: '/var/phpgrc/themes',
      },
      login: {
        layout: 'centered',
      },
    },
    nav: {
      sidebar: {
        default_order: [...DEFAULT_SIDEBAR_ORDER],
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
      assets: {
        filesystem_path: '/var/phpgrc/brand-assets',
      },
    },
  };
}

function etagFor(version: number): string {
  return `W/"ui:playwright:${version}"`;
}

function prefsEtagFor(version: number): string {
  return `W/"prefs:playwright:${version}"`;
}

function headerMatches(candidate: string | null | undefined, etag: string): boolean {
  if (typeof candidate !== 'string' || candidate.trim() === '') {
    return false;
  }

  const values = candidate
    .split(',')
    .map((value) => value.trim())
    .filter((value) => value.length > 0);

  return values.some((value) => value === '*' || value === etag);
}

test.describe('Theme hot-swap', () => {
  test.use({ storageState: './tests/e2e/.auth/admin.json' });

  test.beforeEach(async ({ page }, testInfo) => {
    const theme = resolveTheme(testInfo);
    const initialTheme = 'slate';
    const initialMode: ThemeMode = initialModeFor(initialTheme);

    await page.addInitScript((preferredTheme) => {
      try {
        localStorage.setItem('phpgrc.ui.theme', preferredTheme);
      } catch {
        // ignore storage issues when running under Playwright
      }
    }, theme);

    type State = {
      config: ReturnType<typeof buildUiConfig>;
      prefs: {
        theme: string;
        mode: ThemeMode;
        overrides: Record<string, string | null>;
        sidebar: {
          collapsed: boolean;
          width: number;
          order: string[];
        };
      };
      version: number;
    };

    const state: State = {
      config: buildUiConfig(initialTheme, initialMode),
      prefs: {
        theme: initialTheme,
        mode: initialMode,
        overrides: {},
        sidebar: {
          collapsed: false,
          width: 256,
          order: [...DEFAULT_SIDEBAR_ORDER],
        },
      },
      version: 1,
    };

    await page.route('**/api/**', async (route) => {
      const playwrightRequest = route.request();
      const url = new URL(playwrightRequest.url());
      const method = playwrightRequest.method();
      const currentEtag = etagFor(state.version);

      if (method === 'OPTIONS') {
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

      if (url.pathname === '/api/auth/me') {
        await route.fulfill({
          status: 200,
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({
            ok: true,
            user: {
              id: 1,
              email: 'admin@example.com',
              roles: ['admin'],
            },
          }),
        });
        return;
      }

      if (url.pathname === '/api/settings/ui' || url.pathname === '/settings/ui') {
        if (method === 'GET' || method === 'HEAD') {
          await route.fulfill({
            status: 200,
            headers: {
              'content-type': 'application/json',
              etag: currentEtag,
              ...NO_CACHE_HEADERS,
            },
            body: JSON.stringify({
              ok: true,
              config: {
                ui: state.config,
              },
              etag: currentEtag,
            }),
          });
          return;
        }

        if (method === 'PUT') {
          const ifMatch = await playwrightRequest.headerValue('if-match');
          if (!headerMatches(ifMatch, currentEtag)) {
            await route.fulfill({
              status: 409,
              headers: {
                'content-type': 'application/json',
                etag: currentEtag,
                ...NO_CACHE_HEADERS,
              },
              body: JSON.stringify({
                ok: false,
                code: 'PRECONDITION_FAILED',
                message: 'If-Match header required or did not match current version.',
                current_etag: currentEtag,
              }),
            });
            return;
          }

          const payloadRaw = playwrightRequest.postData();
          const payload = payloadRaw ? JSON.parse(payloadRaw) : {};
          const themePayload = payload?.ui?.theme;

          if (themePayload && typeof themePayload === 'object') {
            const previousTheme = state.config.theme.default;
            const previousMode = state.config.theme.mode;
            const requestedTheme = typeof themePayload.default === 'string' ? themePayload.default : previousTheme;
            const requestedMode =
              themePayload.mode === 'light' || themePayload.mode === 'dark'
                ? themePayload.mode
                : initialModeFor(requestedTheme);
            const requestedOverridesRaw =
              themePayload.overrides && typeof themePayload.overrides === 'object'
                ? (themePayload.overrides as Record<string, unknown>)
                : {};
            const requestedOverrides = Object.fromEntries(
              Object.entries(requestedOverridesRaw).map(([key, value]) => [
                key,
                typeof value === 'string' || value === null ? value : value === undefined ? null : String(value),
              ])
            ) as Record<string, string | null>;

            state.config = buildUiConfig(requestedTheme, requestedMode);
            state.config.theme.allow_user_override = themePayload.allow_user_override ?? true;
            state.config.theme.force_global = themePayload.force_global ?? false;
            state.config.theme.overrides = requestedOverrides;

            state.prefs.theme = requestedTheme;
            state.prefs.mode = requestedMode;
            state.prefs.overrides = { ...requestedOverrides };
            state.version += 1;
            const nextEtag = etagFor(state.version);

            await route.fulfill({
              status: 200,
              headers: {
                'content-type': 'application/json',
                etag: nextEtag,
                ...NO_CACHE_HEADERS,
              },
              body: JSON.stringify({
                ok: true,
                config: {
                  ui: state.config,
                },
                etag: nextEtag,
                changes: [
                  ...(previousTheme !== state.config.theme.default
                    ? [
                        {
                          key: 'ui.theme.default',
                          old: previousTheme,
                          new: state.config.theme.default,
                          action: 'updated',
                        },
                      ]
                    : []),
                  ...(previousMode !== state.config.theme.mode
                    ? [
                        {
                          key: 'ui.theme.mode',
                          old: previousMode,
                          new: state.config.theme.mode,
                          action: 'updated',
                        },
                      ]
                    : []),
                ],
              }),
            });
            return;
          }

          await route.fulfill({
            status: 200,
            headers: {
              'content-type': 'application/json',
              etag: currentEtag,
              ...NO_CACHE_HEADERS,
            },
            body: JSON.stringify({
              ok: true,
              config: {
                ui: state.config,
              },
              etag: currentEtag,
              changes: [],
            }),
          });
          return;
        }

        await route.fulfill({
          status: 405,
          headers: {
            'content-type': 'application/json',
          },
          body: JSON.stringify({ ok: false, code: 'METHOD_NOT_ALLOWED' }),
        });
        return;
      }

      if (url.pathname === '/api/me/prefs/ui' || url.pathname === '/me/prefs/ui') {
        const prefsEtag = prefsEtagFor(state.version);
        if (method === 'GET' || method === 'HEAD') {
          await route.fulfill({
            status: 200,
            headers: {
              'content-type': 'application/json',
              etag: prefsEtag,
              ...NO_CACHE_HEADERS,
            },
            body: JSON.stringify({
              ok: true,
              etag: prefsEtag,
              prefs: state.prefs,
            }),
          });
          return;
        }

        if (method === 'PUT') {
          const ifMatch = await playwrightRequest.headerValue('if-match');
          if (!headerMatches(ifMatch, prefsEtag)) {
            await route.fulfill({
              status: 409,
              headers: {
                'content-type': 'application/json',
                etag: prefsEtag,
                ...NO_CACHE_HEADERS,
              },
              body: JSON.stringify({
                ok: false,
                code: 'PRECONDITION_FAILED',
                message: 'If-Match header required or did not match current version.',
                current_etag: prefsEtag,
              }),
            });
            return;
          }

          const payloadRaw = playwrightRequest.postData();
          if (payloadRaw) {
            try {
              const payload = JSON.parse(payloadRaw);
              if (payload && typeof payload === 'object' && payload.prefs && typeof payload.prefs === 'object') {
                const prefs = payload.prefs;
                if (typeof prefs.theme === 'string') {
                  state.prefs.theme = prefs.theme;
                  state.config.theme.default = prefs.theme;
                }
                if (prefs.mode === 'light' || prefs.mode === 'dark') {
                  state.prefs.mode = prefs.mode;
                  state.config.theme.mode = prefs.mode;
                }
                if (prefs.overrides && typeof prefs.overrides === 'object') {
                  state.prefs.overrides = prefs.overrides;
                  state.config.theme.overrides = prefs.overrides;
                }
              }
            } catch {
              // ignore JSON parse errors; keep previous prefs
            }
          }

          state.version += 1;
          const nextPrefsEtag = prefsEtagFor(state.version);

          await route.fulfill({
            status: 200,
            headers: {
              'content-type': 'application/json',
              etag: nextPrefsEtag,
              ...NO_CACHE_HEADERS,
            },
            body: JSON.stringify({
              ok: true,
              prefs: state.prefs,
              etag: nextPrefsEtag,
            }),
          });
          return;
        }

        await route.fulfill({
          status: 405,
          headers: {
            'content-type': 'application/json',
          },
          body: JSON.stringify({ ok: false, code: 'METHOD_NOT_ALLOWED' }),
        });
        return;
      }

      if (url.pathname === '/api/settings/ui/themes' || url.pathname === '/settings/ui/themes') {
        const manifest = {
          version: '5.3.8',
          defaults: { dark: 'slate', light: 'flatly' },
          themes: [
            {
              slug: 'slate',
              name: 'Slate',
              source: 'bootswatch',
              default_mode: 'dark',
              supports: { mode: ['light', 'dark'] },
              variants: {
                light: { slug: 'slate', name: 'Primary' },
                dark: { slug: 'slate-dark', name: 'Slate Dark' },
              },
            },
            {
              slug: 'flatly',
              name: 'Flatly',
              source: 'bootswatch',
              default_mode: 'light',
              supports: { mode: ['light'] },
              variants: {
                light: { slug: 'flatly', name: 'Primary' },
              },
            },
            {
              slug: 'darkly',
              name: 'Darkly',
              source: 'bootswatch',
              default_mode: 'dark',
              supports: { mode: ['dark'] },
              variants: {
                dark: { slug: 'darkly', name: 'Primary' },
              },
            },
            {
              slug: 'cerulean',
              name: 'Cerulean',
              source: 'bootswatch',
              default_mode: 'light',
              supports: { mode: ['light'] },
              variants: {
                light: { slug: 'cerulean', name: 'Primary' },
              },
            },
          ],
          packs: [],
        };
        await route.fulfill({
          status: 200,
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify(manifest),
        });
        return;
      }

      if (url.pathname === '/api/settings/ui/themes/packs' || url.pathname === '/settings/ui/themes/packs') {
        await route.fulfill({
          status: 200,
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ ok: true, packs: [] }),
        });
        return;
      }

      if (method === 'PUT' || method === 'POST' || method === 'PATCH' || method === 'DELETE') {
        await route.fulfill({
          status: 200,
          headers: { 'content-type': 'application/json', etag: 'W/"ui:playwright"' },
          body: playwrightRequest.postData() ?? JSON.stringify({ ok: true }),
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
            overlay: { loaded: false, path: null, mtime: null },
            summary: { rbac: { enabled: false, require_auth: false, roles_count: 0 } },
          }),
        });
        return;
      }

      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ ok: true }),
      });
    });

    await page.route('**/_playwright/theme', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.fulfill({ status: 405 });
        return;
      }

      const payloadRaw = route.request().postData();
      if (!payloadRaw) {
        await route.fulfill({ status: 400 });
        return;
      }

      try {
        const payload = JSON.parse(payloadRaw) as { theme?: string; mode?: ThemeMode };
        const theme = typeof payload.theme === 'string' ? payload.theme : state.config.theme.default;
        const mode = payload.mode === 'light' || payload.mode === 'dark' ? payload.mode : initialModeFor(theme);

        state.config = buildUiConfig(theme, mode);
        state.prefs.theme = theme;
        state.prefs.mode = mode;
        state.version += 1;
        await route.fulfill({ status: 204 });
      } catch {
        await route.fulfill({ status: 400 });
      }
    });
  });

  for (const { slug, mode } of themes) {
    test(`applies ${slug} (${mode}) without refresh`, async ({ page }) => {
      await selectTheme(page, slug, mode);

      await page.waitForSelector(navSelector, { state: 'visible' });

      const href = await page.evaluate(() => {
        const link = document.getElementById('phpgrc-theme-css') as HTMLLinkElement | null;
        return link?.href ?? null;
      });

      expect(href).toBeTruthy();

      const dataTheme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
      const dataMode = await page.evaluate(() => document.documentElement.getAttribute('data-mode'));
      const dataVariant = await page.evaluate(() => document.documentElement.getAttribute('data-theme-variant'));

      expect(dataTheme).toBe(slug);
      expect(dataMode).toBe(mode);
      expect(dataVariant).not.toBeNull();

      const navbarClass = await page.locator(navSelector).getAttribute('class');
      expect(navbarClass).toBeTruthy();

      for (const route of pages) {
        await page.goto(`/${route}`);
        await page.waitForSelector(navSelector);
        await expect(page.locator('html')).toHaveAttribute('data-theme', slug);
      }
    });
  }
});
