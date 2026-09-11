import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './staging',
  testMatch: 'checkers-layout-diagnostic.spec.mjs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 120_000,
  expect: { timeout: 15_000 },
  reporter: [['line']],
  use: {
    baseURL: process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com',
    browserName: 'chromium',
    headless: true,
    actionTimeout: 15_000,
    navigationTimeout: 35_000,
    ignoreHTTPSErrors: false,
  },
});
