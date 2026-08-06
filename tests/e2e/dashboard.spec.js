// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');

// READONLY commands only — see helpers/terminal.js safety note.

test('dashboard: toggle buttons open/close distinct-container terminals with auto-arrange', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-dashboard');

    // Starts with one open (Alpha) — full space, no divider.
    await expect(page.locator('[data-wts-pane]')).toHaveCount(1);
    await recorder.waitForSocketCount(1);
    await expect(page.locator('[data-wts-source="alpha"]')).toHaveClass(/wts-dashboard-toggle-open/);
    await expect(page.locator('.wts-divider')).toHaveCount(0);

    // Open Bravo → 2 panes, its own socket, one divider (columns).
    await page.locator('[data-wts-source="bravo"]').click();
    await expect(page.locator('[data-wts-pane]')).toHaveCount(2);
    await recorder.waitForSocketCount(2);
    await expect(page.locator('.wts-divider')).toHaveCount(1);
    await expect(page.locator('[data-wts-source="bravo"]')).toHaveClass(/wts-dashboard-toggle-open/);

    // Open Charlie and Delta → 4 panes, 4 sockets.
    await page.locator('[data-wts-source="charlie"]').click();
    await expect(page.locator('[data-wts-pane]')).toHaveCount(3);
    await page.locator('[data-wts-source="delta"]').click();
    await expect(page.locator('[data-wts-pane]')).toHaveCount(4);
    await recorder.waitForSocketCount(4);

    // The four terminals are distinct containers — capture each socket's
    // prompt (container hostname) and confirm they aren't all identical.
    await page.waitForTimeout(1500);
    const hostnames = new Set(
        recorder.sockets.map((s) => {
            const m = recorder.receivedText(recorder.sockets.indexOf(s)).match(/([0-9a-f]{12}):~/);
            return m ? m[1] : null;
        }).filter(Boolean),
    );
    expect(hostnames.size).toBeGreaterThan(1); // not all the same container

    // Close Alpha → back to 3 panes; a sibling's socket must survive the close.
    const survivor = recorder.sockets[1];
    await page.locator('[data-wts-source="alpha"]').click();
    await expect(page.locator('[data-wts-pane]')).toHaveCount(3);
    await expect(page.locator('[data-wts-source="alpha"]')).not.toHaveClass(/wts-dashboard-toggle-open/);
    expect(survivor.closed).toBe(false);
});
