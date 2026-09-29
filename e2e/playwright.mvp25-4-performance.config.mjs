import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './staging',
  testMatch: ['mvp25-4-performance-baseline.spec.mjs'],
  globalSetup: './staging-global-setup.mjs',
  outputDir: 'artifacts/playwright-mvp25-4/test-results',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 120_000,
  expect: { timeout: 15_000 },
  reporter: [
    ['line'],
    ['json', { outputFile: 'artifacts/playwright-mvp25-4/results.json' }],
  ],
  use: {
    baseURL: process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com',
    browserName: 'chromium',
    headless: true,
    actionTimeout: 15_000,
    navigationTimeout: 35_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: false,
  },
});
