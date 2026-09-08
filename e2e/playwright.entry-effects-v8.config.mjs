import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './staging',
  testMatch: /entry-effects-v8-legendary-strike\.spec\.mjs/,
  timeout: 30_000,
  workers: 1,
  use: {
    baseURL: 'http://127.0.0.1:4173',
    viewport: { width: 390, height: 844 },
    locale: 'ru-RU',
  },
});
