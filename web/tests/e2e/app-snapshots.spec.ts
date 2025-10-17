import { expect, test, type Page, type TestInfo } from '@playwright/test';

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

const EVIDENCE_ROWS = [
  {
    id: 'EV-2024-0001',
    owner_id: 1,
    filename: 'Vendor SOC 2 Report.pdf',
    mime: 'application/pdf',
    mime_label: 'PDF',
    size: 2_560_000,
    sha256: '84d9731d3f4f5a4dc3a27995d932ab7032579340b5dca4be2bf8515f9346a2be',
    version: 1,
    created_at: '2024-01-05T16:12:00Z',
  },
  {
    id: 'EV-2024-0002',
    owner_id: 2,
    filename: 'Quarterly Access Review.csv',
    mime: 'text/csv',
    mime_label: 'CSV',
    size: 185_344,
    sha256: 'e23427b1ef1c4ab6356d4b331ebf9c38fabd44073cf582cf8da0b77a97c1d8af',
    version: 3,
    created_at: '2024-01-04T09:03:00Z',
  },
  {
    id: 'EV-2024-0003',
    owner_id: 3,
    filename: 'Firewall Change Ticket.png',
    mime: 'image/png',
    mime_label: 'PNG',
    size: 845_902,
    sha256: '27cc275886f12c22595d3966d45c8dc4c8f08f9d9000b8a7e4fbd3db61c0f932',
    version: 2,
    created_at: '2024-01-03T21:45:00Z',
  },
] as const;

const USER_FIXTURES = new Map([
  [
    1,
    {
      id: 1,
      name: 'Alice Example',
      email: 'alice@example.com',
      roles: ['role_admin'],
    },
  ],
  [
    2,
    {
      id: 2,
      name: 'Ben Reviewer',
      email: 'ben@example.com',
      roles: ['role_theme_auditor'],
    },
  ],
  [
    3,
    {
      id: 3,
      name: 'Casey Ops',
      email: 'casey@example.com',
      roles: ['role_theme_manager'],
    },
  ],
]);

const VIEWPORTS = {
  desktop: { width: 1440, height: 900 },
  mobile: { width: 375, height: 812 },
} as const;

type ViewportKey = keyof typeof VIEWPORTS;

type Surface = {
  slug: string;
  path: string;
  waitFor: (page: Page) => Promise<void>;
};

const SURFACES: readonly Surface[] = [
  {
    slug: 'dashboard',
    path: '/dashboard',
    waitFor: async (page) => {
      await page.waitForSelector('.dashboard-grid', { state: 'visible' });
    },
  },
  {
    slug: 'risks',
    path: '/risks',
    waitFor: async (page) => {
      await page.waitForSelector('h1:has-text("Risks")', { state: 'visible' });
    },
  },
  {
    slug: 'compliance',
    path: '/compliance',
    waitFor: async (page) => {
      await page.waitForSelector('h1:has-text("Compliance")', { state: 'visible' });
    },
  },
  {
    slug: 'policies',
    path: '/policies',
    waitFor: async (page) => {
      await page.waitForSelector('h1:has-text("Policies")', { state: 'visible' });
    },
  },
  {
    slug: 'evidence',
    path: '/evidence',
    waitFor: async (page) => {
      await page.waitForSelector('table[aria-label="Evidence results"]', { state: 'visible' });
    },
  },
  {
    slug: 'exports',
    path: '/exports',
    waitFor: async (page) => {
      await page.waitForSelector('h1:has-text("Exports")', { state: 'visible' });
    },
  },
  {
    slug: 'admin',
    path: '/admin',
    waitFor: async (page) => {
      await page.waitForSelector('nav[aria-label="Admin navigation"]', { state: 'visible' });
    },
  },
  {
    slug: 'profile-theme',
    path: '/profile/theme',
    waitFor: async (page) => {
      await page.waitForSelector('h1:has-text("Theme Preferences")', { state: 'visible' });
    },
  },
] as const;

async function setViewport(page: Page, viewport: ViewportKey): Promise<void> {
  const size = VIEWPORTS[viewport];
  await page.setViewportSize(size);
  await page.emulateMedia({ reducedMotion: 'reduce' });
}

test.beforeEach(async ({ page }, testInfo) => {
  const theme = resolveTheme(testInfo);

  await page.addInitScript(() => {
    const originalMatchMedia = window.matchMedia;
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      value: (query: string) => {
        const mediaQuery = query ?? '';
        const matches = mediaQuery.includes('prefers-reduced-motion');
        const listeners = new Set<(event: MediaQueryListEvent) => void>();
        const mql: MediaQueryList = {
          media: mediaQuery,
          matches,
          onchange: null,
          addListener: (listener: (event: MediaQueryListEvent) => void) => {
            listeners.add(listener);
          },
          removeListener: (listener: (event: MediaQueryListEvent) => void) => {
            listeners.delete(listener);
          },
          addEventListener: (_event: string, listener: (event: MediaQueryListEvent) => void) => {
            listeners.add(listener);
          },
          removeEventListener: (_event: string, listener: (event: MediaQueryListEvent) => void) => {
            listeners.delete(listener);
          },
          dispatchEvent: (event: Event) => {
            listeners.forEach((listener) => listener(event as MediaQueryListEvent));
            return true;
          },
        };
        if (!matches && typeof originalMatchMedia === 'function') {
          return originalMatchMedia.call(window, query);
        }
        return mql;
      },
    });
  });

  await page.addInitScript((preferredTheme) => {
    try {
      localStorage.setItem('phpgrc.ui.theme', preferredTheme);
    } catch {
      // ignore storage failures
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
      const authDays = Number.parseInt(searchParams.get('auth_days') ?? '', 10);
      const data = authDays && Number.isFinite(authDays) ? KPI_FIXTURE : KPI_FIXTURE;
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ data }),
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

    if (pathname === '/api/evidence') {
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          ok: true,
          data: EVIDENCE_ROWS,
          next_cursor: null,
          filters: {},
          time_format: 'ISO_8601',
        }),
      });
      return;
    }

    if (pathname.startsWith('/api/rbac/users/') && pathname.endsWith('/roles')) {
      const segments = pathname.split('/');
      const idSegment = segments[4] ?? '';
      const userId = Number.parseInt(idSegment, 10);
      const user = USER_FIXTURES.get(userId);
      await route.fulfill({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(
          user
            ? {
                ok: true,
                user: { id: user.id, name: user.name, email: user.email },
                roles: user.roles,
              }
            : { ok: false, code: 'USER_NOT_FOUND' }
        ),
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
});

for (const surface of SURFACES) {
  for (const viewport of Object.keys(VIEWPORTS) as ViewportKey[]) {
    test(`${surface.slug} (${viewport})`, async ({ page }, testInfo) => {
      const theme = resolveTheme(testInfo);
      test.skip(!SUPPORTED_THEMES.has(theme), `Snapshots only enforced for ${Array.from(SUPPORTED_THEMES).join(', ')}`);

      await setViewport(page, viewport);

      await page.goto(surface.path);
      await page.waitForLoadState('networkidle');
      await surface.waitFor(page);
      await page.locator('[role="status"], [aria-live]').evaluateAll((elements) => {
        for (const element of elements) {
          element.remove();
        }
      });
      await page
        .getByRole('region', { name: 'Notifications' })
        .evaluateAll((elements) => elements.forEach((element) => ((element as HTMLElement).style.display = 'none')));

      const snapshotName = `${surface.slug}-${viewport}.png`;
      await expect(page).toHaveScreenshot(snapshotName, {
        animations: 'disabled',
        fullPage: true,
        timeout: 15_000,
      });
    });
  }
}
