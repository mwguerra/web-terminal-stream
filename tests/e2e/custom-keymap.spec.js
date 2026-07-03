// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');
const { focusPane } = require('./helpers/terminal');

// READONLY commands only — see helpers/terminal.js safety note.

// This page rebinds the workspace: prefix Ctrl+D, then plain arrows add a
// pane in that direction, Ctrl+Q closes the current pane (never the last).
async function prefix(page) {
    await page.keyboard.press('Control+d');
    await page.waitForTimeout(150);
}

// Read the split tree's top-level pane ids left→right / top→bottom.
function paneOrder(page) {
    return page.evaluate(() => {
        const s = window.Alpine.$data(document.querySelector('[data-wts-workspace]'));
        const ids = [];
        (function walk(n) {
            if (!n) return;
            if (n.type === 'pane') return ids.push(n.paneId);
            walk(n.first);
            walk(n.second);
        })(s.tree);
        return ids;
    });
}

test('custom keymap: Ctrl+D prefix, arrows add directional panes, Ctrl+Q closes but keeps the last', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-custom-keymap');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);
    await recorder.waitForText(0, /\$/);

    const firstId = (await paneOrder(page))[0];

    // Ctrl+D, then Right → new pane to the RIGHT (appended after source).
    await focusPane(page, 0);
    await prefix(page);
    await page.keyboard.press('ArrowRight');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);
    await recorder.waitForSocketCount(2);
    let order = await paneOrder(page);
    expect(order[0]).toBe(firstId); // original stays on the left
    const rightId = order[1];

    // Focus the original (left) pane, Ctrl+D then Left → new pane to its LEFT.
    await focusPane(page, 0);
    await prefix(page);
    await page.keyboard.press('ArrowLeft');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(3);
    order = await paneOrder(page);
    // The brand-new pane leads; the original sits after it, right pane last.
    expect(order[1]).toBe(firstId);
    expect(order[order.length - 1]).toBe(rightId);
    const newLeftId = order[0];
    expect(newLeftId).not.toBe(firstId);

    // Ctrl+D then Ctrl+Q closes the focused pane (the new left one).
    await focusPane(page, 0);
    await prefix(page);
    await page.keyboard.press('Control+q');

    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);

    // Close again down to one.
    await focusPane(page, 0);
    await prefix(page);
    await page.keyboard.press('Control+q');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);

    // Last pane must survive Ctrl+Q.
    await focusPane(page, 0);
    await prefix(page);
    await page.keyboard.press('Control+q');
    await page.waitForTimeout(400);
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
});
