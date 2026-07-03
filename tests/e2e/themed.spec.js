// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');

// READONLY commands only — see helpers/terminal.js safety note.

test('themed workspace applies preset colors + fluent divider/font overrides', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-themed');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);

    // Container CSS variables: TokyoNight background kept, divider width/color
    // overridden fluently.
    const vars = await page.evaluate(() => {
        const cs = getComputedStyle(document.querySelector('[data-wts-workspace]'));
        return {
            width: cs.getPropertyValue('--wts-divider-width').trim(),
            color: cs.getPropertyValue('--wts-divider-color').trim(),
            bg: cs.getPropertyValue('--wts-terminal-bg').trim(),
        };
    });
    expect(vars.width).toBe('3px');
    expect(vars.color.toLowerCase()).toBe('#7aa2f7');
    expect(vars.bg.toLowerCase()).toBe('#1a1b26'); // preset default, not overridden

    // Split so a divider exists, then confirm the themed line actually renders.
    await page.evaluate(async () => {
        const s = window.Alpine.$data(document.querySelector('[data-wts-workspace]'));
        await s.split('horizontal');
    });
    await expect(page.locator('.wts-divider-vertical')).toHaveCount(1);

    const border = await page.locator('.wts-divider-vertical').evaluate(
        (el) => getComputedStyle(el, '::before').borderLeftWidth,
    );
    expect(border).toBe('3px');
});
