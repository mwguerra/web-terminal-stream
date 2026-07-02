// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * E2E suite against the scaffolded host app (tests/e2e-app), started by
 * scripts/e2e/run.sh: app on :8000, WebSocket server on :8091, sshd target
 * on :2299. workers: 1 — every spec drives real PTYs on the same server.
 */
module.exports = defineConfig({
    testDir: 'tests/e2e',
    workers: 1,
    fullyParallel: false,
    timeout: 60_000,
    globalSetup: require.resolve('./tests/e2e/global-setup'),
    use: {
        baseURL: 'http://127.0.0.1:8000',
        storageState: 'test-results/.auth.json',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
