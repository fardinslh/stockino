import { chromium } from 'playwright-core';

const executablePath = process.env.STOCKINO_BROWSER_PATH ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.STOCKINO_ORDERINO_URL ?? 'http://localhost:8080';
const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const failures = [];
page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
page.on('console', (message) => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);

  await page.goto(`${baseUrl}/wp-admin/admin.php?page=orderino`, { waitUntil: 'networkidle' });
  await page.locator('#orderino-admin-root').waitFor();
  const orderinoAssets = await page.locator('script[src],link[href]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('src') ?? node.getAttribute('href') ?? ''));
  if (!orderinoAssets.some((url) => url.includes('/plugins/orderino/'))) failures.push('Orderino page did not load its scoped assets');
  if (orderinoAssets.some((url) => url.includes('/plugins/stockino/'))) failures.push('Stockino assets leaked onto the Orderino page');

  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino`, { waitUntil: 'networkidle' });
  await page.locator('#stockino-admin-root h1').waitFor();
  const inventoryAssets = await page.locator('script[src],link[href]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('src') ?? node.getAttribute('href') ?? ''));
  if (!inventoryAssets.some((url) => url.includes('/plugins/stockino/'))) failures.push('Stockino inventory page did not load its scoped assets');
  if (inventoryAssets.some((url) => url.includes('/plugins/orderino/'))) failures.push('Orderino assets leaked onto the Stockino page');

  await page.goto(`${baseUrl}/wp-admin/admin.php?page=stockino-suppliers`, { waitUntil: 'networkidle' });
  await page.locator('.stockino-suppliers-app h1').waitFor();
  const rest = await page.request.get(`${baseUrl}/?rest_route=/`);
  const namespaces = (await rest.json()).namespaces;
  if (!namespaces.includes('orderino/v1') || !namespaces.includes('stockino/v1')) failures.push('Separate REST namespaces are not both registered');
} finally {
  await browser.close();
}

if (failures.length) throw new Error(`Coexistence QA failed:\n${failures.join('\n')}`);
console.log('Orderino and Stockino coexistence QA passed with separate pages, assets, and REST namespaces.');
