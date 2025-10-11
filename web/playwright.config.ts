import { defineConfig, devices } from '@playwright/test';

const PORT = Number(process.env.PLAYWRIGHT_PORT ?? 4173);
const HOST = process.env.PLAYWRIGHT_HOST ?? '127.0.0.1';
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? `http://${HOST}:${PORT}`;

const themes = ['slate', 'flatly', 'darkly'] as const;

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['github']] : [['list']],
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: process.env.PLAYWRIGHT_SKIP_WEBSERVER
    ? undefined
    : {
        command: 'npm run build && npm run preview -- --host 127.0.0.1 --port ' + PORT,
        url: BASE_URL,
        reuseExistingServer: !process.env.CI,
        stdout: 'pipe',
        stderr: 'pipe',
      },
  projects: themes.map((theme) => ({
    name: `chromium-${theme}`,
    use: {
      ...devices['Desktop Chrome'],
      metadata: { theme },
    },
  })),
});
