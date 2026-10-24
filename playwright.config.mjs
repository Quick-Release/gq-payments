import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/browser',
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8988',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /.*\.setup\.mjs/ },
    {
      name: 'admin',
      testMatch: /.*\.spec\.mjs/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: '.playwright/admin.json' },
    },
  ],
});
