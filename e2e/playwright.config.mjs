import { defineConfig } from '@playwright/test';

const outputRoot = 'artifacts/playwright';
const runLiveTournamentMutation = process.env.MGW_STAGING_LIVE_TOURNAMENT_E2E === '1';
const currentTests = ['current-core-final.spec.mjs', 'checkers-layout-diagnostic.spec.mjs', 'go-store-live-catalog.spec.mjs'];
if (runLiveTournamentMutation) currentTests.push('tournament-registration-live.spec.mjs');

// Blocking staging acceptance follows the exact Telegram launch entry and the
// current v110 product contract. Automatic runs must not mutate the live official
// tournament used for manual acceptance. Live tournament mutation remains available
// only through the explicit MGW_STAGING_LIVE_TOURNAMENT_E2E=1 opt-in.
// Historical version-pinned suites remain in the separate legacy config.
export default defineConfig({
  testDir: './staging',
  testMatch: currentTests,
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
