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
const createdOrderIds = [];
page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
page.on('console', (message) => { if (message.type() === 'error') failures.push(`console: ${message.text()} (${message.location().url})`); });
const reorderResponse = (method = 'GET') => page.waitForResponse((response) => decodeURIComponent(response.url()).includes('/stockino/v1/reorder') && response.request().method() === method && response.status() < 400);
const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino-reorder`, { waitUntil: 'domcontentloaded' });
  await page.locator('.stockino-reorder-app h1').waitFor();
  await page.locator('.stockino-reorder-table tbody tr').first().waitFor();
  if (await page.locator('#stockino-admin-root').evaluate((element) => getComputedStyle(element).direction) !== 'rtl') failures.push('reorder direction is not RTL');
  if (await overflow() > 1) failures.push(`desktop reorder page overflow: ${await overflow()}px`);

  const filters = page.locator('.stockino-reorder-filters select');
  await Promise.all([reorderResponse(), filters.nth(0).selectOption('no_supplier')]);
  await page.locator('.stockino-reorder-table tbody tr.is-reorder-no_supplier').first().waitFor();
  await Promise.all([reorderResponse(), filters.nth(0).selectOption('attention_required')]);
  await Promise.race([
    page.locator('.stockino-reorder-table tbody tr.is-reorder-attention_required').first().waitFor(),
    page.locator('.stockino-no-results').waitFor(),
  ]);
  await Promise.all([reorderResponse(), filters.nth(0).selectOption('reorder_needed')]);
  const readyRows = page.locator('.stockino-reorder-table tbody tr.is-reorder-reorder_needed');
  const readyRow = readyRows.first();
  await readyRow.waitFor();
  await readyRow.getByRole('button', { name: /جزئیات/ }).click();
  await page.locator('#stockino-reorder-detail').waitFor();
  await page.locator('.stockino-reorder-equation').waitFor();
  await page.locator('.stockino-incoming-list').waitFor();
  await page.locator('.stockino-incoming-list .stockino-skeleton').waitFor({ state: 'detached' });
  await page.screenshot({ path: path.join(artifactDir, 'stockino-reorder-desktop-detail.png'), fullPage: true });
  await page.locator('.stockino-reorder-drawer > header .stockino-icon-button').click();

  await readyRow.locator('button[aria-label^="تنظیمات"]').click();
  await page.locator('.stockino-reorder-settings[role=dialog]').waitFor();
  await page.locator('.stockino-reorder-settings select').waitFor();
  await page.locator('.stockino-reorder-settings .stockino-icon-button').click();

  const checkboxes = readyRows.locator('input[type=checkbox]:enabled');
  if (await checkboxes.count() < 2) throw new Error('Browser QA requires two selectable recommendations.');
  const checkbox = checkboxes.last();
  const secondCheckbox = checkboxes.nth((await checkboxes.count()) - 2);
  const checkboxLabel = await checkbox.getAttribute('aria-label');
  const ownerId = Number((await checkbox.locator('xpath=ancestor::tr').innerText()).match(/OWNER #(\d+)/)?.[1]);
  if (!checkboxLabel || !ownerId) throw new Error('Ready recommendation checkbox has no parseable accessible label.');
  await checkbox.check();
  await secondCheckbox.check();
  const competing = await page.evaluate(async (id) => {
    const response = await fetch(`${window.stockinoSettings.root}reorder/create-purchase-orders`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.stockinoSettings.nonce }, body: JSON.stringify({ stock_owner_ids: [id] }) });
    return { status: response.status, body: await response.json() };
  }, ownerId);
  if (competing.status !== 201 || competing.body.created?.length !== 1) throw new Error(`Competing draft fixture failed: ${JSON.stringify(competing)}`);
  createdOrderIds.push(...competing.body.created.map((item) => item.purchase_order.id));
  const [created] = await Promise.all([reorderResponse('POST'), page.getByRole('button', { name: /ساخت پیش‌نویس خرید/ }).click()]);
  if (created.status() !== 201) throw new Error(`Draft creation returned ${created.status()}: ${await created.text()}`);
  const createdPayload = await created.json();
  createdOrderIds.push(...createdPayload.created.map((item) => item.purchase_order.id));
  await page.locator('.stockino-reorder-result-links a').first().waitFor();
  await page.locator('.stockino-reorder-result li').first().waitFor();
  await page.screenshot({ path: path.join(artifactDir, 'stockino-reorder-desktop-result.png'), fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino-reorder`, { waitUntil: 'domcontentloaded' });
  await page.locator('.stockino-reorder-table tbody tr').first().waitFor();
  let mobileOverflow = await overflow();
  if (mobileOverflow > 1) failures.push(`mobile reorder list overflow: ${mobileOverflow}px`);
  await page.locator('.stockino-reorder-table tbody tr').first().getByRole('button', { name: /جزئیات/ }).evaluate((button) => button.click());
  await page.locator('#stockino-reorder-detail').waitFor();
  mobileOverflow = await overflow();
  if (mobileOverflow > 1) failures.push(`mobile reorder detail overflow: ${mobileOverflow}px`);
  await page.getByRole('button', { name: /تنظیم نقطه/ }).evaluate((button) => button.click());
  await page.locator('.stockino-reorder-settings[role=dialog]').waitFor();
  await page.locator('.stockino-reorder-settings select').waitFor();
  mobileOverflow = await overflow();
  if (mobileOverflow > 1) failures.push(`mobile reorder settings overflow: ${mobileOverflow}px`);
  await page.screenshot({ path: path.join(artifactDir, 'stockino-reorder-mobile.png'), fullPage: true });
} catch (error) {
  await page.screenshot({ path: path.join(artifactDir, 'stockino-reorder-failure.png'), fullPage: true }).catch(() => undefined);
  console.error(`URL: ${page.url()}`);
  console.error(`Browser failures: ${failures.join(' | ') || 'none captured'}`);
  console.error(`Page text: ${(await page.locator('body').innerText().catch(() => '')).slice(-1800)}`);
  throw error;
} finally {
  await page.evaluate(async (ids) => {
    await Promise.all(ids.map((id) => fetch(`${window.stockinoSettings.root}purchase-orders/${id}/cancel`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.stockinoSettings.nonce }, body: '{}' })));
  }, createdOrderIds).catch(() => undefined);
  await browser.close();
}

if (failures.length) throw new Error(`Reorder browser QA failed:\n${failures.join('\n')}`);
console.log('Stockino reorder QA passed at 1440px and 390px with explanation, settings, draft, stale-request, RTL, overflow, and console checks.');
