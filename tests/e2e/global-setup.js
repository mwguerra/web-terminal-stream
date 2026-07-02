// @ts-check
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

/**
 * Authenticate once through the E2E_TESTING backdoor route and persist the
 * session cookie as storageState for every spec.
 */
module.exports = async () => {
    const authDir = path.resolve(__dirname, '../../test-results');
    fs.mkdirSync(authDir, { recursive: true });

    const browser = await chromium.launch();
    const page = await browser.newPage();

    await page.goto('http://127.0.0.1:8000/e2e/login');
    await page.waitForURL('**/admin**');

    await page.context().storageState({ path: path.join(authDir, '.auth.json') });
    await browser.close();
};
