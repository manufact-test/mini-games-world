import { defineConfig } from '@playwright/test';

const outputRoot = 'artifacts/playwright';

// Blocking staging acceptance follows the exact Telegram launch entry and the
// current v110 product contract. Historical version-pinned suites remain in the
// separate playwright.legacy.config.mjs archive.
export default defineConfig({
  testDir: './staging',
  testMatch: 'current-core-live.spec.mjs',
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
