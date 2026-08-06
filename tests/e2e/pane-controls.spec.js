// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');
const { focusPane, prefixKey } = require('./helpers/terminal');

// READONLY commands only — see helpers/terminal.js safety note.

test('workspace shows a help description at the top of the page', async ({ page }) => {
    await page.goto('/admin/e2e-workspace');

    // The page subheading explains how to create/close/navigate panes.
    await expect(page.getByText(/split left\/right/i)).toBeVisible();
    await expect(page.getByText(/close pane/i)).toBeVisible();
});

test('per-pane close button: shown only when >1 pane, closes that pane, last pane fills', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-workspace');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);
    await recorder.waitForText(0, /\$/);

    // A single pane has no close button and fills the workspace.
    await expect(page.locator('.wts-pane-close:visible')).toHaveCount(0);
    const workspace = page.locator('[data-wts-workspace]');
    const wsBox = await workspace.boundingBox();
    const solo = await page.locator('[data-wts-pane]').first().boundingBox();
    expect(Math.abs(solo.width - wsBox.width)).toBeLessThan(4);

    // Split → both panes now expose a close button.
    await focusPane(page, 0);
    await prefixKey(page, 'Shift+Digit5');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);
    await expect(page.locator('.wts-pane-close:visible')).toHaveCount(2);

    // Click the first pane's close button → that pane closes, one remains.
    await page.locator('.wts-pane-close').first().click();
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);

    // The sole survivor has no close button and fills the full component again.
    await expect(page.locator('.wts-pane-close:visible')).toHaveCount(0);
    await expect
        .poll(async () => {
            const b = await page.locator('[data-wts-pane]').first().boundingBox();
            const w = await workspace.boundingBox();
            return Math.abs(b.width - w.width) < 4 && Math.abs(b.height - w.height) < 4;
        })
        .toBe(true);
});
