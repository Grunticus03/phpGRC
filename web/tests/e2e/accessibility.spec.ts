import { expect, test, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PLACEHOLDER_PNG =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAn8B9nSTaQAAAABJRU5ErkJggg==';
const FIXED_ISO_DATE = '2024-01-01T00:00:00.000Z';
const SUPPORTED_THEMES = new Set(['slate', 'flatly']);

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

  const nameSegments = testInfo.project.name.split('-');
  const lastSegment = nameSegments[nameSegments.length - 1];
  if (SUPPORTED_THEMES.has(lastSegment)) {
    return lastSegment;
  }

  return 'slate';
}

const KPI_FIXTURE = {
  auth_activity: {
    window_days: 30,
    from: FIXED_ISO_DATE,
    to: '2024-01-30T00:00:00.000Z',
    max_daily_total: 60,
    totals: { success: 210, failed: 18, total: 228 },
    daily: [
      { date: '2024-01-01', success: 40, failed: 3, total: 43 },
      { date: '2024-01-02', success: 32, failed: 1, total: 33 },
      { date: '2024-01-03', success: 28, failed: 2, total: 30 },
      { date: '2024-01-04', success: 45, failed: 4, total: 49 },
      { date: '2024-01-05', success: 34, failed: 3, total: 37 },
      { date: '2024-01-06', success: 22, failed: 2, total: 24 },
      { date: '2024-01-07', success: 9, failed: 3, total: 12 },
    ],
  },
  evidence_mime: {
    total: 58,
    by_mime: [
      { mime: 'application/pdf', mime_label: 'PDF', count: 24, percent: 0.41 },
      { mime: 'image/png', mime_label: 'PNG', count: 18, percent: 0.31 },
      { mime: 'text/csv', mime_label: 'CSV', count: 10, percent: 0.17 },
      { mime: 'application/zip', mime_label: 'ZIP', count: 6, percent: 0.11 },
    ],
  },
  admin_activity: {
    admins: [
      { id: 1, name: 'Alice Example', email: 'alice@example.com', last_login_at: '2024-01-08T13:24:00Z' },
      { id: 2, name: 'Ben Reviewer', email: 'ben@example.com', last_login_at: '2024-01-04T08:12:00Z' },
      { id: 3, name: 'Casey Ops', email: 'casey@example.com', last_login_at: null },
    ],
  },
};

const BRAND_CONFIG = {
  title_text: 'phpGRC — Playwright',
  favicon_asset_id: null,
  primary_logo_asset_id: null,
  secondary_logo_asset_id: null,
  header_logo_asset_id: null,
  footer_logo_asset_id: null,
  footer_logo_disabled: false,
  assets: {
    filesystem_path: '/opt/phpgrc/shared/brands',
  },
};

const BRAND_PROFILES = [
  {
    id: 'default',
    name: 'Default Profile',
    is_default: true,
    is_active: true,
    is_locked: true,
    brand: BRAND_CONFIG,
    created_at: '2023-12-15T12:00:00Z',
    updated_at: '2023-12-15T12:00:00Z',
  },
];

const ROLE_OPTIONS = [
  { id: 'role_admin', name: 'Administrator' },
  { id: 'role_theme_manager', name: 'Theme Manager' },
  { id: 'role_theme_auditor', name: 'Theme Auditor' },
];

const USERS_FIXTURE = {
  ok: true,
  data: [
    { id: 1, name: 'Alice Example', email: 'alice@example.com', roles: ['role_admin'] },
    { id: 2, name: 'Ben Reviewer', email: 'ben@example.com', roles: ['role_theme_auditor'] },
    { id: 3, name: 'Casey Ops', email: 'casey@example.com', roles: ['role_theme_manager'] },
  ],
  meta: { page: 1, per_page: 25, total: 3, total_pages: 1 },
};

const AUDIT_FIXTURE = {
  items: [
    {
      id: 'evt-1',
      occurred_at: '2024-01-07T17:45:00Z',
      actor: { id: 1, type: 'user', label: 'Alice Example' },
      category: 'auth',
      action: 'login.success',
      ip: '192.0.2.10',
      meta: { method: 'password' },
    },
    {
      id: 'evt-2',
      occurred_at: '2024-01-07T18:12:00Z',
      actor: { id: 2, type: 'user', label: 'Ben Reviewer' },
      category: 'settings',
      action: 'theme.updated',
      ip: '192.0.2.11',
      meta: { theme: 'flatly' },
    },
  ],
  time_format: 'ISO_8601',
};

type Surface = {
  name: string;
  path: string;
  waitFor: (page: Page) => Promise<void>;
};

const SURFACES: readonly Surface[] = [
  {
    name: 'dashboard',
    path: '/dashboard',
    waitFor: async (page) => {
      await page.waitForSelector('.dashboard-grid', { state: 'visible' });
    },
  },
  {
    name: 'theme-settings',
    path: '/admin/settings/theming',
    waitFor: async (page) => {
      await page.waitForSelector('section.card[aria-label="theme-configurator"]', { state: 'visible' });
    },
  },
  {
    name: 'branding',
    path: '/admin/settings/branding',
    waitFor: async (page) => {
      await page.waitForSelector('section.card[aria-label="branding-card"]', { state: 'visible' });
    },
  },
  {
    name: 'users',
    path: '/admin/users',
    waitFor: async (page) => {
      await page.waitForSelector('h1:has-text("User Management")', { state: 'visible' });
    },
  },
  {
    name: 'audit',
    path: '/admin/audit',
    waitFor: async (page) => {
      await page.waitForSelector('h1:has-text("Audit Logs")', { state: 'visible' });
    },
  },
];

async function mockApi(page: Page, theme: string): Promise<void> {
  await page.addInitScript((preferredTheme) => {
    try {
      localStorage.setItem('phpgrc.ui.theme', preferredTheme);
    } catch {
      // ignore storage failures in init
    }
  }, theme);

  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    const { pathname, searchParams } = url;
    const method = route.request().method();

    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { 'access-control-allow-origin': '*' } });
      return;
    }

    if (pathname.startsWith('/api/images/')) {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'image/png' },
        body: Buffer.from(PLACEHOLDER_PNG, 'base64'),
      });
      return;
    }

    if (pathname === '/api/health/fingerprint') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          ok: true,
          fingerprint: 'playwright',
          summary: { rbac: { require_auth: false } },
        }),
      });
      return;
    }

    if (pathname === '/api/dashboard/kpis') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ data: KPI_FIXTURE }),
      });
      return;
    }

    if (pathname === '/api/settings/ui/themes' || pathname === '/settings/ui/themes') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          version: '5.3.8',
          themes: [
            {
              slug: 'slate',
              name: 'Slate',
              source: 'bootswatch',
              supports: { mode: ['dark'] },
              variants: ['default'],
            },
            {
              slug: 'flatly',
              name: 'Flatly',
              source: 'bootswatch',
              supports: { mode: ['light'] },
              variants: ['default'],
            },
          ],
          packs: [],
        }),
      });
      return;
    }

    if (pathname === '/api/settings/ui' || pathname === '/settings/ui') {
      await route.fulfill({
        status: 200,
        headers: {
          'content-type': 'application/json',
          ETag: 'W/"ui:playwright"',
        },
        body: JSON.stringify({
          ok: true,
          config: {
            ui: {
              theme: {
                default: theme,
                allow_user_override: true,
                force_global: false,
                overrides: {
                  'color.background': theme === 'flatly' ? '#f8f9fa' : '#1a1d21',
                  'color.surface': theme === 'flatly' ? '#ffffff' : '#20242a',
                  'color.accent': '#0d6efd',
                },
                designer: {
                  storage: 'filesystem',
                  filesystem_path: '/opt/phpgrc/shared/themes',
                },
                login: { layout: 'layout_3' },
              },
              nav: {
                sidebar: {
                  default_order: ['dashboard', 'evidence', 'metrics', 'risks', 'policies', 'compliance'],
                },
              },
              brand: BRAND_CONFIG,
            },
          },
          etag: 'W/"ui:playwright"',
        }),
      });
      return;
    }

    if (pathname === '/api/me/prefs/ui' || pathname === '/me/prefs/ui') {
      await route.fulfill({
        status: 200,
        headers: {
          'content-type': 'application/json',
          ETag: 'W/"prefs:playwright"',
        },
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
              order: ['dashboard', 'evidence', 'metrics', 'risks', 'policies', 'compliance'],
            },
          },
        }),
      });
      return;
    }

    if (pathname === '/api/settings/ui/brand-profiles') {
      if (method === 'GET') {
        await route.fulfill({
          status: 200,
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ ok: true, profiles: BRAND_PROFILES }),
        });
        return;
      }

      if (method === 'POST') {
        const profile = {
          ...BRAND_PROFILES[0],
          id: 'profile-' + Date.now().toString(36),
          is_default: false,
          is_locked: false,
          is_active: false,
        };
        await route.fulfill({
          status: 201,
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ ok: true, profile }),
        });
        return;
      }
    }

    if (pathname.startsWith('/api/settings/ui/brand-profiles/') && pathname.endsWith('/activate')) {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ ok: true }),
      });
      return;
    }

    if (
      pathname.startsWith('/api/settings/ui/brand-profiles/') &&
      method === 'DELETE' &&
      !pathname.endsWith('/activate')
    ) {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ ok: true }),
      });
      return;
    }

    if (pathname === '/api/settings/ui/brand-assets') {
      if (method === 'GET') {
        await route.fulfill({
          status: 200,
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ ok: true, assets: [] }),
        });
        return;
      }

      if (method === 'POST') {
        await route.fulfill({
          status: 201,
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ ok: true }),
        });
        return;
      }
    }

    if (pathname.startsWith('/api/settings/ui/brand-assets/') && method === 'DELETE') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ ok: true }),
      });
      return;
    }

    if (pathname === '/api/rbac/roles') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ ok: true, roles: ROLE_OPTIONS }),
      });
      return;
    }

    if (pathname === '/api/users') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(USERS_FIXTURE),
      });
      return;
    }

    if (pathname === '/api/audit/categories') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ categories: ['auth', 'settings', 'users'] }),
      });
      return;
    }

    if (pathname === '/api/audit') {
      const limitRaw = searchParams.get('limit');
      const limit = limitRaw ? Number.parseInt(limitRaw, 10) : null;
      const items = limit && Number.isFinite(limit) ? AUDIT_FIXTURE.items.slice(0, Math.max(limit, 1)) : AUDIT_FIXTURE.items;
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ items, time_format: AUDIT_FIXTURE.time_format }),
      });
      return;
    }

    if (method === 'PUT' || method === 'POST' || method === 'PATCH' || method === 'DELETE') {
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
}

async function scanSurface(page: Page): Promise<void> {
  const axe = new AxeBuilder({ page });
  const results = await axe.analyze();
  expect(results.violations).toEqual([]);
}

for (const surface of SURFACES) {
  test.describe(`${surface.name} accessibility`, () => {
    test(`${surface.name} passes axe`, async ({ page }, testInfo) => {
      const theme = resolveTheme(testInfo);
      test.skip(!SUPPORTED_THEMES.has(theme), `Accessibility enforced for ${Array.from(SUPPORTED_THEMES).join(', ')}`);

      await mockApi(page, theme);
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.goto(surface.path);
      await page.waitForLoadState('networkidle');
      await surface.waitFor(page);

      await scanSurface(page);
    });
  });
}
