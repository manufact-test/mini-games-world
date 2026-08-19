import { defineConfig } from '@playwright/test';

const outputRoot = 'artifacts/playwright-legacy';
const supersededScenarios = /D1 follow-up: (declined invitation remains read history without actions or toast|mobile cached invitation wins over a delayed false-empty response|desktop bell opens during an unfinished request and ignores its stale finish)|Player A uses Share, player picker and cancellation through the live UI/;

// Historical acceptance archive. This intentionally keeps the accumulated v120-v123
// scenarios and superseded current-core iterations available for postmortem/manual
// runs, but it is not the blocking owner of the current Telegram v110 staging runtime.
export default defineConfig({
  testDir: './staging',
  testIgnore: ['current-core-live.spec.mjs'],
  globalSetup: './staging-global-setup.mjs',
  grepInvert: supersededScenarios,
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
