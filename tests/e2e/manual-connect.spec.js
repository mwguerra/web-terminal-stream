// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');

// READONLY commands only — see helpers/terminal.js safety note.

test('manual connect: no WebSocket until the Connect overlay is clicked', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-manual');
    await expect(page.locator('.stream-web-terminal')).toBeVisible();

    // A manual pane must not open any socket on its own.
    await page.waitForTimeout(2000);
    expect(recorder.sockets.length).toBe(0);

    await page.locator('[data-connect-overlay] button').click();

    await recorder.waitForSocketCount(1);
    await recorder.waitForText(0, /\$/);

    await page.locator('.stream-web-terminal').click();
    await page.keyboard.type('echo wts-e2e-manual-$((50+5))\n');

    await recorder.waitForText(0, /wts-e2e-manual-55/);
});
