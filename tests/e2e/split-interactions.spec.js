// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');
const { focusPane, prefixKey } = require('./helpers/terminal');

// READONLY commands only — see helpers/terminal.js safety note.

test('workspace interactions: zoom toggle, divider drag resize, keyboard focus nav', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-workspace');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);
    await recorder.waitForText(0, /\$/);

    // Split side-by-side; focus lands on the new right-hand pane.
    await focusPane(page, 0);
    await prefixKey(page, 'Shift+Digit5');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);
    await recorder.waitForSocketCount(2);
    await recorder.waitForText(1, /\$/);

    const panes = page.locator('[data-wts-pane]');

    // --- Zoom: prefix+z zooms the focused pane, hides the sibling. ---
    await focusPane(page, 1);
    await prefixKey(page, 'z');

    await expect(panes.nth(1)).toHaveClass(/wts-pane-zoomed/);
    await expect
        .poll(() => panes.nth(0).evaluate((el) => getComputedStyle(el).visibility))
        .toBe('hidden');

    // prefix+z again restores.
    await prefixKey(page, 'z');
    await expect(panes.nth(1)).not.toHaveClass(/wts-pane-zoomed/);
    await expect
        .poll(() => panes.nth(0).evaluate((el) => getComputedStyle(el).visibility))
        .toBe('visible');

    // --- Divider drag: pane geometry changes and a resize frame goes out. ---
    const widthBefore = (await panes.nth(0).boundingBox()).width;
    const resizeFramesBefore = recorder.sockets
        .flatMap((s) => s.sent)
        .filter((f) => f.includes('"type":"resize"')).length;

    const divider = page.locator('.wts-divider').first();
    const box = await divider.boundingBox();
    const startX = box.x + box.width / 2;
    const startY = box.y + box.height / 2;

    await page.mouse.move(startX, startY);
    await page.mouse.down();
    // Several small steps so rAF-throttled handlers see a real drag.
    for (let i = 1; i <= 10; i++) {
        await page.mouse.move(startX + i * 12, startY);
    }
    await page.mouse.up();

    await expect
        .poll(async () => (await panes.nth(0).boundingBox()).width)
        .not.toBe(widthBefore);

    await expect
        .poll(
            () => recorder.sockets.flatMap((s) => s.sent).filter((f) => f.includes('"type":"resize"')).length,
            { timeout: 10_000 },
        )
        .toBeGreaterThan(resizeFramesBefore);

    // --- Focus navigation: prefix+ArrowLeft moves focus to the left pane. ---
    await focusPane(page, 1);
    await prefixKey(page, 'ArrowLeft');

    await expect
        .poll(() =>
            page.evaluate(() => {
                const pane = document.querySelectorAll('[data-wts-pane]')[0];
                return pane ? pane.contains(document.activeElement) : false;
            }),
        )
        .toBe(true);
});
