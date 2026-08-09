import { chromium } from 'playwright-core';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const executablePath = process.env.STOCKINO_BROWSER_PATH
  ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.STOCKINO_BASE_URL ?? 'http://localhost:8088';
const artifactDir = path.resolve('tests/artifacts');
await mkdir(artifactDir, { recursive: true });

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const failures = [];
page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
page.on('console', (message) => {
  if (message.type() === 'error') failures.push(`console: ${message.text()}`);
});

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([
    page.waitForURL(/wp-admin/),
    page.locator('#wp-submit').click(),
  ]);

  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino`, { waitUntil: 'networkidle' });
  await page.locator('#stockino-admin-root h1').filter({ hasText: 'مدیریت موجودی' }).waitFor();
  await page.locator('.stockino-table tbody tr').first().waitFor();

  const direction = await page.locator('#stockino-admin-root').evaluate((element) => getComputedStyle(element).direction);
  if (direction !== 'rtl') failures.push(`direction: expected rtl, got ${direction}`);
  const rows = await page.locator('.stockino-table tbody tr').count();
  if (rows < 1) failures.push('inventory: no rows rendered');

  await page.locator('.stockino-text-action:not([disabled])').first().click();
  await page.locator('#stockino-adjust-title').waitFor();
  await page.locator('.stockino-dialog .stockino-icon-button').click();
  await page.locator('.stockino-row-actions button:nth-child(2)').first().click();
  await page.locator('#stockino-history-title').waitFor();
  await page.locator('.stockino-drawer .stockino-icon-button').click();
  await page.screenshot({ path: path.join(artifactDir, 'stockino-desktop.png'), fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('.stockino-table tbody tr').first().waitFor();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile overflow: ${overflow}px`);
  await page.screenshot({ path: path.join(artifactDir, 'stockino-mobile.png'), fullPage: true });
} catch (error) {
  await page.screenshot({ path: path.join(artifactDir, 'stockino-failure.png'), fullPage: true }).catch(() => undefined);
  console.error(`URL: ${page.url()}`);
  console.error(`Browser failures: ${failures.join(' | ') || 'none captured'}`);
  console.error(`Page text: ${(await page.locator('body').innerText().catch(() => '')).slice(-1200)}`);
  throw error;
} finally {
  await browser.close();
}

if (failures.length) {
  throw new Error(`Browser QA failed:\n${failures.join('\n')}`);
}

console.log('Stockino browser QA passed at 1440px and 390px with a clean console.');
