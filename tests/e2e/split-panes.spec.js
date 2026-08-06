// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');
const { focusPane, prefixKey } = require('./helpers/terminal');

// READONLY commands only — see helpers/terminal.js safety note.

test('workspace split: isolated per-pane sockets, first pane survives, close collapses', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-workspace');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);
    await recorder.waitForText(0, /\$/);

    // Split horizontally (side-by-side): prefix, then % (Shift+5).
    await focusPane(page, 0);
    await prefixKey(page, 'Shift+Digit5');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);
    await recorder.waitForSocketCount(2);
    await recorder.waitForText(1, /\$/);

    // KILL CRITERION: the original pane's socket must survive the split —
    // no close event while a sibling is added.
    expect(recorder.sockets[0].closed).toBe(false);

    // Type into the new (focused) pane; output must land on socket B only.
    await focusPane(page, 1);
    await page.keyboard.type('echo wts-only-b-$((100+1))\n');

    await recorder.waitForText(1, /wts-only-b-101/);
    expect(recorder.receivedText(0)).not.toContain('wts-only-b-101');
    expect(recorder.sockets[0].closed).toBe(false);

    // Close the focused pane: prefix, then x. One pane remains.
    await prefixKey(page, 'x');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    expect(recorder.sockets[0].closed).toBe(false);
});
