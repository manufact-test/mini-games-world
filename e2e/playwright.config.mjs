import { defineConfig } from '@playwright/test';

const outputRoot = 'artifacts/playwright';

// Blocking staging acceptance follows the exact Telegram launch entry and the
// current v110 product contract. Historical version-pinned suites remain in the
// separate playwright.legacy.config.mjs archive.
export default defineConfig({
  testDir: './staging',
  testMatch: ['current-core-final.spec.mjs', 'store-frame-avatar-actions.spec.mjs'],
  // #1153's original Store probe lived outside the real top-level avatar grid.
  // #1154 intentionally narrowed the production selector so frame previews can
  // never acquire avatar actions. Keep the full TTT lifecycle from that file,
  // but replace only the stale Store fixture with the boundary-aware smoke test.
  grepInvert: /^STORE SMOKE: avatar decorator cannot freeze bottom navigation$/,
  globalSetup: './staging-global-setup.mjs',
  outputDir: `${outputRoot}/test-results`,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 120_000,
  expect: {
    timeout: 15_000,
  },
  reporter: [
    ['line'],
    ['json', { outputFile: `${outputRoot}/results.json` }],
    ['html', { outputFolder: `${outputRoot}/html`, open: 'never' }],
  ],
  use: {
    baseURL: process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com',
    browserName: 'chromium',
    headless: true,
    launchOptions: {
      args: [
        '--disable-background-timer-throttling',
        '--disable-backgrounding-occluded-windows',
        '--disable-renderer-backgrounding',
      ],
    },
    actionTimeout: 15_000,
    navigationTimeout: 35_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: false,
  },
});
