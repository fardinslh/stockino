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
page.on('console', (message) => { if (message.type() === 'error') failures.push(`console: ${message.text()} (${message.location().url})`); });
const apiResponse = (fragment, method = 'GET') => page.waitForResponse((response) => decodeURIComponent(response.url()).includes(fragment) && response.request().method() === method && response.status() < 400);

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino-purchase-orders`, { waitUntil: 'networkidle' });
  await page.locator('.stockino-purchasing-app h1').waitFor();
  await page.locator('.stockino-purchase-table tbody tr').first().waitFor();
  const direction = await page.locator('#stockino-admin-root').evaluate((element) => getComputedStyle(element).direction);
  if (direction !== 'rtl') failures.push(`direction: expected rtl, got ${direction}`);

  await page.locator('.stockino-header-action').click();
  await page.locator('#stockino-create-po').waitFor();
  await page.locator('.stockino-dialog select').selectOption({ label: 'تأمین کالای سپاهان' });
  await page.locator('.stockino-dialog input[dir=ltr]').first().fill(`BROWSER-${Date.now()}`);
  await Promise.all([apiResponse('/stockino/v1/purchase-orders', 'POST'), page.locator('.stockino-dialog form footer .stockino-button-primary').click()]);
  await page.locator('#stockino-po-detail').waitFor();
  await page.getByRole('button', { name: /افزودن قلم/ }).click();
  await page.locator('#stockino-add-po-line').waitFor();
  const productSearch = page.getByLabel('جستجوی محصول تأمین‌کننده');
  await productSearch.fill('STK-101');
  await apiResponse('/products');
  await page.locator('.stockino-picker-results button').first().click();
  await page.locator('.stockino-link-dialog input[inputmode=decimal]').fill('2');
  await Promise.all([apiResponse('/items', 'POST'), page.locator('.stockino-link-dialog form footer .stockino-button-primary').click()]);
  await page.getByRole('button', { name: 'ثبت سفارش' }).click();
  await apiResponse('/mark-ordered', 'POST');
  await page.getByRole('button', { name: /دریافت کالا/ }).click();
  await page.locator('#stockino-receive').waitFor();
  await page.locator('.stockino-receive-lines input').first().fill('1');
  await Promise.all([apiResponse('/receipts', 'POST'), page.locator('.stockino-receive-dialog form footer .stockino-button-primary').click()]);
  await page.locator('.stockino-purchase-drawer .stockino-po-status.is-partial').waitFor();
  await page.locator('.stockino-receipt-history > button').first().click();
  await page.locator('#stockino-receipt-detail').waitFor();
  await page.locator('.stockino-receipt-detail article').first().waitFor();
  await page.screenshot({ path: path.join(artifactDir, 'stockino-purchasing-desktop.png'), fullPage: true });
  await page.locator('.stockino-receipt-dialog .stockino-icon-button').click();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'لغو سفارش' }).click();
  await apiResponse('/cancel', 'POST');
  await page.locator('.stockino-purchase-drawer .stockino-po-status.is-cancelled').waitFor();
  await page.locator('.stockino-purchase-drawer > header .stockino-icon-button').click();

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('.stockino-purchase-table tbody tr').first().waitFor();
  let overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile purchase list overflow: ${overflow}px`);
  await page.locator('.stockino-po-number').first().click();
  await page.locator('#stockino-po-detail').waitFor();
  overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile purchase detail overflow: ${overflow}px`);
  await page.screenshot({ path: path.join(artifactDir, 'stockino-purchasing-mobile.png'), fullPage: true });
} catch (error) {
  await page.screenshot({ path: path.join(artifactDir, 'stockino-purchasing-failure.png'), fullPage: true }).catch(() => undefined);
  console.error(`URL: ${page.url()}`);
  console.error(`Browser failures: ${failures.join(' | ') || 'none captured'}`);
  console.error(`Page text: ${(await page.locator('body').innerText().catch(() => '')).slice(-1800)}`);
  throw error;
} finally {
  await browser.close();
}

if (failures.length) throw new Error(`Purchasing browser QA failed:\n${failures.join('\n')}`);
console.log('Stockino purchasing QA passed at 1440px and 390px with a clean console.');
