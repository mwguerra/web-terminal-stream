// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');

// READONLY commands only — see helpers/terminal.js safety note.

/*
 * The three title-bar dots, which were decorative until 1.1.1.
 *
 * These assert BEHAVIOUR, not markup: a unit test can only prove the HTML
 * mentions `requestClose()`, which is not the same as a click closing a pane.
 */

test('window controls: red closes a dashboard pane and frees its socket', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-dashboard');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);

    // Open a second pane so we can close one and still see a survivor.
    await page.locator('[data-wts-source="bravo"]').click();
    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);
    await recorder.waitForSocketCount(2);

    const survivor = recorder.sockets[1];

    // Red dot on the first pane.
    await page.locator('[data-wts-pane]').first().locator('.wts-window-close').first().click();

    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);

    // Closing one pane must not disturb the other's live PTY.
    expect(survivor.closed).toBeFalsy();

    // And the source returns to the toggle bar rather than vanishing — closing
    // is the same operation as toggling off, so it must be reopenable.
    await expect(page.locator('[data-wts-source="alpha"]')).not.toHaveClass(/wts-dashboard-toggle-open/);
});

test('window controls: yellow behaves as close, same as red', async ({ page }) => {
    await page.goto('/admin/e2e-dashboard');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);

    await page.locator('[data-wts-source="bravo"]').click();
    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);

    // Second dot = yellow.
    await page.locator('[data-wts-pane]').first().locator('.wts-window-close').nth(1).click();

    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
});

test('window controls: green toggles fullscreen and keeps the PTY alive', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-dashboard');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);

    const terminal = page.locator('.stream-web-terminal').first();
    const before = await terminal.boundingBox();

    // Green is the third dot; it is not a .wts-window-close.
    const green = terminal.locator('button.rounded-full.bg-\\[\\#27c93f\\]');
    await green.click();

    await expect(terminal).toHaveClass(/wts-fullscreen/);

    const full = await terminal.boundingBox();
    const viewport = page.viewportSize();
    expect(full.width).toBeGreaterThan(before.width);
    expect(Math.round(full.width)).toBe(viewport.width);

    // The whole point of not re-parenting the node: the session survives.
    expect(recorder.sockets[0].closed).toBeFalsy();

    // Toggling back restores the original geometry.
    await green.click();
    await expect(terminal).not.toHaveClass(/wts-fullscreen/);

    const restored = await terminal.boundingBox();
    expect(Math.round(restored.width)).toBe(Math.round(before.width));
});

test('window controls: a standalone terminal shows its dots as inert', async ({ page }) => {
    // Nothing can close a terminal that is not in a container, so the dots must
    // not pretend otherwise.
    await page.goto('/admin/e2e-single');

    const dots = page.locator('.wts-window-close');
    await expect(dots.first()).toHaveAttribute('aria-disabled', 'true');
});

test('rounded corners: no square divider colour behind the pane corners', async ({ page }) => {
    await page.goto('/admin/e2e-dashboard');
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await page.waitForTimeout(800);

    // The container that paints the divider colour must clip to the same
    // rounded silhouette as the pane, or its square corner shows behind each
    // rounded one.
    const radii = await page.evaluate(() => {
        const pane = document.querySelector('.stream-web-terminal');
        const container = document.querySelector('.wts-dashboard-panes');

        return {
            pane: getComputedStyle(pane).borderTopLeftRadius,
            container: getComputedStyle(container).borderTopLeftRadius,
        };
    });

    expect(radii.container).not.toBe('0px');
    expect(radii.container).toBe(radii.pane);

    // Visual record of the corner, kept as the artefact for review.
    await page.locator('.wts-dashboard-panes').screenshot({
        path: 'tests/e2e/artifacts/rounded-corners.png',
    });
});
