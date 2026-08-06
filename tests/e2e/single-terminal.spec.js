// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');

// READONLY commands only — see helpers/terminal.js safety note.

test('single terminal: one WebSocket, echo round-trip, resize frame on window resize', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-single');

    await recorder.waitForSocketCount(1);
    // Wait for the remote shell prompt before typing.
    await recorder.waitForText(0, /\$/);

    await page.locator('.stream-web-terminal').click();
    // $((...)) expands remotely: the typed line never contains the marker,
    // so a match proves real command output came back.
    await page.keyboard.type('echo wts-e2e-single-$((40+2))\n');

    await recorder.waitForText(0, /wts-e2e-single-42/);

    const resizeFramesBefore = recorder.sockets[0].sent.filter((f) => f.includes('"type":"resize"')).length;

    await page.setViewportSize({ width: 900, height: 720 });

    await expect
        .poll(
            () => recorder.sockets[0].sent.filter((f) => f.includes('"type":"resize"')).length,
            { timeout: 10_000 },
        )
        .toBeGreaterThan(resizeFramesBefore);
});
