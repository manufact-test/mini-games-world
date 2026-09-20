import { defineConfig } from '@playwright/test';

const outputRoot = 'artifacts/playwright';
const supersededD1StressScenarios = /D1 follow-up: (declined invitation remains read history without actions or toast|mobile cached invitation wins over a delayed false-empty response|desktop bell opens during an unfinished request and ignores its stale finish)/;

export default defineConfig({
  testDir: './staging',
  globalSetup:'./staging-global-setup.mjs',
  grepInvert:supersededD1StressScenarios,
  outputDir: `${outputRoot}/test-results`,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
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
    actionTimeout: 15_000,
    navigationTimeout: 35_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: false,
  },
});