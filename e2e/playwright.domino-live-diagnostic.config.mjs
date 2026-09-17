import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './staging',
  testMatch: ['domino-live-stability-v30.spec.mjs'],
  outputDir: 'artifacts/playwright-domino-live/test-results',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  expect: {
    timeout: 10_000,
  },
  reporter: [
    ['line'],
    ['json', { outputFile: 'artifacts/playwright-domino-live/results.json' }],
  ],
  use: {
    baseURL: process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com',
    browserName: 'chromium',
    headless: true,
    actionTimeout: 10_000,
    navigationTimeout: 25_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: false,
  },
});
