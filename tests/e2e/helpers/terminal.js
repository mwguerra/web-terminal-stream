// @ts-check

/*
 * SAFETY: every terminal in the e2e app connects via SSH to the throwaway
 * docker sshd container (127.0.0.1:2299) — never a workstation shell. Even
 * so, ONLY READONLY COMMANDS may be typed in any spec:
 *   echo, pwd, ls, date, whoami, stty size, printenv
 * Never type anything that writes, deletes, or mutates state.
 */

/**
 * Click into the nth workspace pane (0-based) so keyboard input goes to its
 * terminal.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} n
 */
async function focusPane(page, n) {
    const pane = page.locator('[data-wts-pane]').nth(n);
    await pane.locator('.stream-web-terminal').click();
}

/**
 * Send a tmux-style prefix chord: Control+b, then `key` (a
 * page.keyboard.press() token, e.g. 'Shift+Digit5', 'x', 'ArrowLeft').
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 */
async function prefixKey(page, key) {
    await page.keyboard.press('Control+b');
    // Give the armed-state machine a beat before the action key.
    await page.waitForTimeout(150);
    await page.keyboard.press(key);
}

module.exports = { focusPane, prefixKey };
