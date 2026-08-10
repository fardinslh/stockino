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
page.on('response', (response) => { if (response.url().includes('/stockino/dist/') && response.headers()['content-type']?.includes('text/html')) failures.push(`asset MIME: ${response.url()}`); });
const apiResponse = (fragment, method = 'GET') => page.waitForResponse((response) => decodeURIComponent(response.url()).includes(fragment) && response.request().method() === method && response.status() < 400);

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino-suppliers`, { waitUntil: 'networkidle' });
  await page.locator('.stockino-suppliers-app h1').waitFor();
  await page.locator('.stockino-supplier-table tbody tr').first().waitFor();
  const direction = await page.locator('#stockino-admin-root').evaluate((element) => getComputedStyle(element).direction);
  if (direction !== 'rtl') failures.push(`direction: expected rtl, got ${direction}`);

  const search = page.locator('.stockino-supplier-toolbar .stockino-search-field input');
  await search.fill('SUP-001');
  await apiResponse('/stockino/v1/suppliers');
  await page.locator('.stockino-supplier-table tbody tr').first().waitFor();
  if (await page.locator('.stockino-supplier-table tbody tr').count() !== 1) failures.push('supplier search did not return one row');

  await page.locator('.stockino-header-action').click();
  await page.locator('#stockino-supplier-form-title').waitFor();
  await page.locator('.stockino-supplier-dialog input[required]').fill('');
  await page.locator('.stockino-supplier-dialog form footer .stockino-button-primary').click();
  const invalid = await page.locator('.stockino-supplier-dialog input[required]').evaluate((input) => !input.checkValidity());
  if (!invalid) failures.push('required supplier name validation is not active');
  await page.locator('.stockino-supplier-dialog .stockino-icon-button').click();

  await page.locator('.stockino-supplier-name').click();
  await page.locator('#stockino-supplier-detail-title').waitFor();
  await page.locator('.stockino-relation-list article').first().waitFor();
  await page.screenshot({ path: path.join(artifactDir, 'stockino-suppliers-desktop.png'), fullPage: true });

  await page.locator('.stockino-supplier-products .stockino-button-primary').click();
  await page.locator('#stockino-link-title').waitFor();
  const pickerSearch = page.locator('.stockino-link-dialog .stockino-search-field input');
  await pickerSearch.fill('STK-110-L');
  await apiResponse('/stockino/v1/products/search');
  await page.locator('.stockino-picker-results button').first().click();
  await page.locator('.stockino-link-dialog input').nth(1).fill('QA-BROWSER-110-L');
  await page.locator('.stockino-link-dialog input').nth(3).fill('3.5');
  await Promise.all([apiResponse('/stockino/v1/suppliers/', 'POST'), page.locator('.stockino-link-dialog form footer .stockino-button-primary').click()]);
  const relation = page.locator('.stockino-relation-list article').filter({ hasText: 'QA-BROWSER-110-L' });
  await relation.waitFor();
  await relation.locator('.stockino-text-action').first().click();
  await page.locator('#stockino-link-title').waitFor();
  await page.locator('.stockino-link-dialog input').nth(1).fill('2');
  await Promise.all([apiResponse('/products/', 'PUT'), page.locator('.stockino-link-dialog form footer .stockino-button-primary').click()]);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([apiResponse('/products/', 'DELETE'), relation.locator('.stockino-danger-action').click()]);

  await page.locator('.stockino-supplier-profile .stockino-text-action').click();
  await page.locator('#stockino-supplier-form-title').waitFor();
  await page.locator('.stockino-supplier-dialog .stockino-icon-button').click();
  await page.locator('.stockino-supplier-drawer > header .stockino-icon-button').click();

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('.stockino-supplier-table tbody tr').first().waitFor();
  await page.locator('.stockino-mobile-add').click();
  await page.locator('#stockino-supplier-form-title').waitFor();
  let overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile supplier form overflow: ${overflow}px`);
  await page.locator('.stockino-supplier-dialog .stockino-icon-button').click();
  await page.locator('.stockino-supplier-name').first().click();
  await page.locator('#stockino-supplier-detail-title').waitFor();
  overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile supplier detail overflow: ${overflow}px`);
  await page.screenshot({ path: path.join(artifactDir, 'stockino-suppliers-mobile.png'), fullPage: true });
} catch (error) {
  await page.screenshot({ path: path.join(artifactDir, 'stockino-suppliers-failure.png'), fullPage: true }).catch(() => undefined);
  console.error(`URL: ${page.url()}`);
  console.error(`Browser failures: ${failures.join(' | ') || 'none captured'}`);
  console.error(`Page text: ${(await page.locator('body').innerText().catch(() => '')).slice(-1400)}`);
  throw error;
} finally {
  await browser.close();
}

if (failures.length) throw new Error(`Supplier browser QA failed:\n${failures.join('\n')}`);
console.log('Stockino supplier QA passed at 1440px and 390px with a clean console.');
