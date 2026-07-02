// @ts-check
const { test, expect } = require('@playwright/test');

// This file runs UNAUTHENTICATED — no storageState.
test.use({ storageState: { cookies: [], origins: [] } });

test('ws-token endpoint rejects unauthenticated requests', async ({ page }) => {
    const response = await page.request.post('/terminal-stream/ws-token', {
        failOnStatusCode: false,
        maxRedirects: 0,
    });

    // web+auth middleware: redirect to login, 401/403, or 419 (CSRF runs
    // before auth on the web stack). Anything but success is the contract.
    expect([302, 401, 403, 419]).toContain(response.status());
});

test('WebSocket server closes a connection with a garbage token without opening', async ({ page }) => {
    await page.goto('/');

    const result = await page.evaluate(
        () =>
            new Promise((resolve) => {
                const ws = new WebSocket('ws://127.0.0.1:8091/?token=garbage');
                let opened = false;
                ws.onopen = () => {
                    opened = true;
                };
                ws.onclose = () => resolve({ opened, closed: true });
                setTimeout(() => resolve({ opened, closed: false }), 8000);
            }),
    );

    expect(result.opened).toBe(false);
    expect(result.closed).toBe(true);
});
