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
const apiResponse = (fragment, method = 'GET') => page.waitForResponse((response) => decodeURIComponent(response.url()).includes(fragment) && response.request().method() === method);

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino-valuation`, { waitUntil: 'networkidle' });
  await page.locator('.stockino-valuation-app h1').waitFor();
  await page.locator('.stockino-valuation-table tbody tr').first().waitFor();
  if (await page.locator('#stockino-admin-root').evaluate((element) => getComputedStyle(element).direction) !== 'rtl') failures.push('valuation direction is not RTL');
  if (await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) > 1) failures.push('desktop valuation page overflow');

  const filters = page.locator('.stockino-valuation-filters select');
  await Promise.all([apiResponse('stockino/v1/valuation'), filters.nth(0).selectOption('positive')]);
  await Promise.all([apiResponse('stockino/v1/valuation'), filters.nth(1).selectOption('uncosted')]);
  const firstRow = page.locator('.stockino-valuation-table tbody tr').first();
  await firstRow.getByRole('button', { name: /تاریخچه/ }).click();
  await page.locator('#stockino-cost-history').waitFor();
  await page.getByRole('button', { name: 'ثبت بهای اولیه' }).click();
  const costDialog = page.locator('.stockino-cost-modal .stockino-dialog');
  await costDialog.locator('input[inputmode=decimal]').fill('11.250000');
  await costDialog.locator('textarea').fill('Browser QA opening-cost audit');
  const initialSubmit = costDialog.locator('form footer .stockino-button-primary');
  if (!(await initialSubmit.isEnabled())) throw new Error(`Initial submit stayed disabled: cost=${await costDialog.locator('input[inputmode=decimal]').inputValue()} reason=${await costDialog.locator('textarea').inputValue()}`);
  const [initialResponse] = await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'),
    costDialog.locator('form').evaluate((form) => form.requestSubmit()),
  ]);
  if (initialResponse.status() >= 400) throw new Error(`Initial cost failed: ${initialResponse.status()} ${await initialResponse.text()}`);
  await page.getByRole('button', { name: 'اصلاح کنترل‌شده بها' }).waitFor();
  await page.getByRole('button', { name: 'اصلاح کنترل‌شده بها' }).click();
  await costDialog.locator('input[inputmode=decimal]').fill('11.500000');
  await costDialog.locator('textarea').fill('Browser QA invoice correction');
  const [correctionResponse] = await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'),
    costDialog.locator('form').evaluate((form) => form.requestSubmit()),
  ]);
  if (correctionResponse.status() >= 400) throw new Error(`Cost correction failed: ${correctionResponse.status()} ${await correctionResponse.text()}`);
  await page.locator('.stockino-cost-history-list article').first().waitFor();
  await page.screenshot({ path: path.join(artifactDir, 'stockino-valuation-desktop.png'), fullPage: true });
  await page.locator('.stockino-cost-drawer > header .stockino-icon-button').click();

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('.stockino-valuation-table tbody tr').first().waitFor();
  let overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile valuation list overflow: ${overflow}px`);
  await page.locator('.stockino-valuation-table tbody tr').first().getByRole('button', { name: /تاریخچه/ }).click();
  await page.locator('#stockino-cost-history').waitFor();
  overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) failures.push(`mobile valuation history overflow: ${overflow}px`);
  await page.screenshot({ path: path.join(artifactDir, 'stockino-valuation-mobile.png'), fullPage: true });
} catch (error) {
  await page.screenshot({ path: path.join(artifactDir, 'stockino-valuation-failure.png'), fullPage: true }).catch(() => undefined);
  console.error(`URL: ${page.url()}`);
  console.error(`Browser failures: ${failures.join(' | ') || 'none captured'}`);
  console.error(`Page text: ${(await page.locator('body').innerText().catch(() => '')).slice(-1800)}`);
  throw error;
} finally {
  await browser.close();
}

if (failures.length) throw new Error(`Valuation browser QA failed:\n${failures.join('\n')}`);
console.log('Stockino valuation QA passed at 1440px and 390px with audited initial/correction flows and a clean console.');
