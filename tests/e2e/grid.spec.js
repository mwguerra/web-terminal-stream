// @ts-check
const { test, expect } = require('@playwright/test');
const { WsRecorder } = require('./helpers/ws-recorder');

// READONLY commands only — see helpers/terminal.js safety note.

test('grid: two flush side-by-side panes, isolated sockets, focus ring follows keyboard focus', async ({ page }) => {
    const recorder = new WsRecorder(page);

    await page.goto('/admin/e2e-grid');

    const terminals = page.locator('.wts-terminal-grid .stream-web-terminal');
    await expect(terminals).toHaveCount(2);

    // Grid default behavior is Always: both panes auto-connect.
    await recorder.waitForSocketCount(2);
    await recorder.waitForText(0, /\$/);
    await recorder.waitForText(1, /\$/);

    // columns(2): panes share a row, pane B strictly right of pane A.
    const boxA = await terminals.nth(0).boundingBox();
    const boxB = await terminals.nth(1).boundingBox();
    if (!boxA || !boxB) {
        throw new Error('grid panes did not render visible boxes');
    }
    expect(Math.abs(boxA.y - boxB.y)).toBeLessThan(2);
    expect(boxB.x).toBeGreaterThanOrEqual(boxA.x + boxA.width - 2);

    // Type into pane A; $((...)) expands remotely, so a match proves real
    // shell output. Exactly one socket must ever carry the marker.
    await terminals.nth(0).click();
    await page.keyboard.type('echo wts-e2e-grid-$((30+3))\n');

    await expect
        .poll(
            () => recorder.sockets.filter((s) => s.received.join('').includes('wts-e2e-grid-33')).length,
            { timeout: 15_000 },
        )
        .toBe(1);
    expect(recorder.sockets.filter((s) => s.received.join('').includes('wts-e2e-grid-33')).length).toBe(1);

    // Neither socket closed as a side effect of activity in the other.
    expect(recorder.sockets[0].closed).toBe(false);
    expect(recorder.sockets[1].closed).toBe(false);

    // Focused-pane ring is pure CSS (:focus-within): pane A shows the
    // outline, pane B does not; clicking B moves the ring with focus.
    await expect(terminals.nth(0)).toHaveCSS('outline-style', 'solid');
    await expect(terminals.nth(1)).toHaveCSS('outline-style', 'none');

    await terminals.nth(1).click();
    await expect(terminals.nth(1)).toHaveCSS('outline-style', 'solid');
    await expect(terminals.nth(0)).toHaveCSS('outline-style', 'none');
});
