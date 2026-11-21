import { defineConfig, devices } from '@playwright/test'

/**
 * Journeys against the ownCloud.online dev stack (make dev-up) and the
 * Synaplan test stack. One worker: the journeys share the server, the app's
 * mode and the person's Synaplan link. Each test sets up the state it starts
 * from, so a retry runs on its own. See README, section Development.
 */
export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.ts',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  timeout: 90_000,
  expect: { timeout: 30_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.OCO_URL ?? 'http://localhost:8083',
    locale: 'en-US',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
