import { chromium } from 'playwright-core';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const executablePath = process.env.STOCKINO_BROWSER_PATH ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.STOCKINO_BASE_URL ?? 'http://localhost:8088';
const artifactDir = path.resolve('tests/artifacts');
await mkdir(artifactDir, { recursive: true });

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const failures = [];

page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
page.on('console', (message) => {
  if (message.type() === 'error') failures.push(`console: ${message.text()} (${message.location().url})`);
});
page.on('response', (response) => {
  if (response.url().includes('/stockino/dist/') && response.headers()['content-type']?.includes('text/html')) {
    failures.push(`asset MIME: ${response.url()}`);
  }
});

const apiResponse = (fragment, method = 'GET') =>
  page.waitForResponse((response) =>
    decodeURIComponent(response.url()).includes(fragment) &&
    response.request().method() === method &&
    response.status() < 400
  );

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);

  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino-marketplaces`, { waitUntil: 'networkidle' });
  await page.locator('.stockino-page-header h1').filter({ hasText: 'بازارگاه‌ها و انتشار محصول' }).waitFor();

  const direction = await page.locator('#stockino-admin-root').evaluate((element) => getComputedStyle(element).direction);
  if (direction !== 'rtl') failures.push(`direction: expected rtl, got ${direction}`);

  // Test tabs
  const tabs = page.locator('button[type=button]').filter({ hasText: 'اتصال به بازارگاه‌ها' });
  await tabs.click();
  await page.locator('.stockino-card h3').first().waitFor();

  // Test connection modal
  await page.locator('.stockino-card button').first().click();
  await page.locator('.stockino-dialog h2').filter({ hasText: 'تنظیمات اتصال' }).waitFor();
  await page.locator('.stockino-dialog .stockino-icon-button').click();

  // Return to products tab
  await page.locator('button[type=button]').filter({ hasText: 'محصولات و انتشار' }).click();
  await page.screenshot({ path: path.join(artifactDir, 'stockino-marketplaces-desktop.png'), fullPage: true });

  // Test responsive 390px
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('.stockino-page-header h1').waitFor();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile overflow: ${overflow}px`);
  await page.screenshot({ path: path.join(artifactDir, 'stockino-marketplaces-mobile.png'), fullPage: true });
} catch (error) {
  await page.screenshot({ path: path.join(artifactDir, 'stockino-marketplaces-failure.png'), fullPage: true }).catch(() => undefined);
  console.error(`URL: ${page.url()}`);
  console.error(`Browser failures: ${failures.join(' | ') || 'none captured'}`);
  console.error(`Page text: ${(await page.locator('body').innerText().catch(() => '')).slice(-1200)}`);
  throw error;
} finally {
  await browser.close();
}

if (failures.length) {
  throw new Error(`Marketplace Browser QA failed:\n${failures.join('\n')}`);
}

console.log('Stockino Marketplace QA passed at 1440px and 390px with clean console.');
