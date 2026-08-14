import { chromium } from 'playwright-core';

const executablePath = process.env.STOCKINO_BROWSER_PATH
  ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.STOCKINO_BASE_URL ?? 'http://localhost:8088';
const pages = [
  ['inventory', 'stockino'],
  ['suppliers', 'stockino-suppliers'],
  ['purchase orders', 'stockino-purchase-orders'],
  ['valuation', 'stockino-valuation'],
  ['reorder', 'stockino-reorder'],
];
const viewports = [
  { name: 'desktop', width: 1440, height: 1000 },
  { name: 'mobile', width: 390, height: 844 },
];

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: viewports[0] });
const failures = [];
page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
page.on('console', (message) => {
  if (message.type() === 'error' && !message.location().url.endsWith('/favicon.ico')) {
    failures.push(`console: ${message.text()}`);
  }
});

try {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);

  for (const viewport of viewports) {
    await page.setViewportSize(viewport);

    for (const [name, slug] of pages) {
      await page.goto(`${baseUrl}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
      const root = page.locator('#stockino-admin-root');
      await root.locator('h1').waitFor();
      await page.evaluate(() => document.fonts.ready);

      const audit = await root.evaluate((element) => {
        const style = getComputedStyle(element);
        const controls = [...element.querySelectorAll('button,input,select,textarea')];
        const leafText = [...element.querySelectorAll('*')]
          .filter((node) => [...node.childNodes].some((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim()))
          .filter((node) => node.getClientRects().length > 0);
        const textNodes = [...new Set([...leafText, ...controls.filter((node) => node.getClientRects().length > 0)])];
        const clipped = textNodes.filter((node) => {
          if (node.matches('.screen-reader-text,[aria-hidden="true"]')) return false;
          const computed = getComputedStyle(node);
          if ((computed.overflowX === 'visible' && computed.overflowY === 'visible') || computed.textOverflow === 'ellipsis') return false;
          return node.scrollHeight > node.clientHeight + 1 || node.scrollWidth > node.clientWidth + 1;
        }).map((node) => `${node.tagName.toLowerCase()}.${node.className || '-'}:${node.textContent?.trim().slice(0, 40)}`);
        const wrongFonts = textNodes.filter((node) => !getComputedStyle(node).fontFamily.includes('Vazirmatn')).length;
        const tinyText = textNodes.filter((node) => Number.parseFloat(getComputedStyle(node).fontSize) < 11).length;
        const unsupportedWeights = textNodes.filter((node) => !['400', '500', '600', '700'].includes(getComputedStyle(node).fontWeight)).length;
        const badLtr = [...element.querySelectorAll("[dir='ltr']")]
          .filter((node) => {
            const computed = getComputedStyle(node);
            return node.getClientRects().length > 0 && (computed.direction !== 'ltr' || computed.unicodeBidi !== 'isolate');
          }).length;
        const smallControls = controls.filter((node) => {
          if (node instanceof HTMLInputElement && ['checkbox', 'radio'].includes(node.type)) return false;
          const rect = node.getBoundingClientRect();
          return rect.width > 0 && rect.height > 0 && (rect.width < 40 || rect.height < 40);
        }).length;

        return {
          direction: style.direction,
          family: style.fontFamily,
          overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          clipped,
          wrongFonts,
          tinyText,
          unsupportedWeights,
          badLtr,
          smallControls,
        };
      });

      if (audit.direction !== 'rtl') failures.push(`${name}/${viewport.name}: direction ${audit.direction}`);
      if (!audit.family.includes('Vazirmatn')) failures.push(`${name}/${viewport.name}: root font ${audit.family}`);
      if (audit.overflow > 1) failures.push(`${name}/${viewport.name}: page overflow ${audit.overflow}px`);
      if (audit.clipped.length) failures.push(`${name}/${viewport.name}: clipped ${audit.clipped.join(', ')}`);
      if (audit.wrongFonts) failures.push(`${name}/${viewport.name}: ${audit.wrongFonts} nodes use another font`);
      if (audit.tinyText) failures.push(`${name}/${viewport.name}: ${audit.tinyText} text nodes below 11px`);
      if (audit.unsupportedWeights) failures.push(`${name}/${viewport.name}: ${audit.unsupportedWeights} nodes use unsupported weights`);
      if (audit.badLtr) failures.push(`${name}/${viewport.name}: ${audit.badLtr} mixed-direction nodes are not isolated`);
      if (audit.smallControls) failures.push(`${name}/${viewport.name}: ${audit.smallControls} controls below 40px`);
    }
  }

  for (const weight of ['400', '500', '600', '700']) {
    const loaded = await page.evaluate(async (fontWeight) => {
      await document.fonts.load(`${fontWeight} 16px Vazirmatn`, 'موجودی iPhone 16 Pro 123');
      return document.fonts.check(`${fontWeight} 16px Vazirmatn`, 'موجودی iPhone 16 Pro 123');
    }, weight);
    if (!loaded) failures.push(`Vazirmatn weight ${weight} did not load`);
  }

  const fontAssets = await page.evaluate(() => performance.getEntriesByType('resource')
    .map((entry) => entry.name)
    .filter((url) => url.endsWith('.woff2')));
  const localFontAssets = new Set(fontAssets.filter((url) => url.includes('/stockino/dist/assets/Vazirmatn-')));
  if (localFontAssets.size !== 4) failures.push(`expected 4 local Vazirmatn assets, found ${localFontAssets.size}`);
  if (fontAssets.some((url) => !url.includes('/stockino/dist/assets/Vazirmatn-'))) failures.push('an external or unrelated font asset loaded');
} finally {
  await browser.close();
}

if (failures.length) throw new Error(`Typography QA failed:\n${failures.join('\n')}`);
console.log('Stockino typography QA passed on all five pages at 1440px and 390px with loaded local fonts, clean console, and no overflow.');
