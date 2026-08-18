/**
 * Headed-mode debug runner for the Wildberries scraper.
 *
 * Opens a REAL visible browser window with the exact same stealth/context
 * settings as the pool so we can watch what WB's antibot actually does.
 *
 * Usage (inside the playwright container):
 *   node debug-wb.js
 * Env knobs: DEBUG_QUERY (default "iphone"), DEBUG_PAUSE_MS (default 15000)
 * Screenshots are written to /app/debug-out when that dir is mounted.
 */
import fs from 'node:fs';
import { chromium as playwrightChromium } from 'playwright-extra';
import stealth from 'puppeteer-extra-plugin-stealth';

const QUERY = process.env.DEBUG_QUERY || 'iphone';
const PAUSE_MS = Number(process.env.DEBUG_PAUSE_MS || 15000);
const OUT_DIR = '/app/debug-out';

const CONTEXT_OPTIONS = {
  locale: 'ru-RU',
  timezoneId: 'Europe/Moscow',
  viewport: { width: 1920, height: 1080 },
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  extraHTTPHeaders: { 'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.8' },
};

const CARD_SELECTOR =
  'article.product-card, article[class*="product-card"], .j-card, a[href*="/catalog/"][href*="detail.aspx"]';

const log = (...args) => console.log('[debug]', ...args);

async function shot(page, name) {
  if (!fs.existsSync(OUT_DIR)) {
    return;
  }
  const file = `${OUT_DIR}/${name}.png`;
  await page.screenshot({ path: file, fullPage: false }).catch(() => null);
  log('screenshot saved:', file);
}

playwrightChromium.use(stealth());

const browser = await playwrightChromium.launch({
  headless: false, // <-- the whole point of this script
  args: [
    '--disable-dev-shm-usage',
    '--no-sandbox',
    '--disable-blink-features=AutomationControlled',
  ],
});

const context = await browser.newContext(CONTEXT_OPTIONS);
const page = await context.newPage();

try {
  log('STEP 1: homepage warm-up -> https://www.wildberries.ru/');
  const homeResp = await page.goto('https://www.wildberries.ru/', {
    waitUntil: 'domcontentloaded',
    timeout: 20000,
  });
  log('homepage status:', homeResp?.status());
  log('homepage title:', await page.title().catch(() => ''));
  log('homepage final url:', page.url());
  await page.waitForTimeout(2000);
  await shot(page, 'wb-01-homepage');

  const searchUrl = 'https://www.wildberries.ru/search?query=' + encodeURIComponent(QUERY);
  log('STEP 2: search ->', searchUrl);
  const searchResp = await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
  log('search status:', searchResp?.status());
  log('search title:', await page.title().catch(() => ''));
  log('search final url:', page.url());
  await shot(page, 'wb-02-search');

  log('STEP 3: scrolling to trigger lazy load...');
  for (let i = 0; i < 4; i += 1) {
    await page.evaluate(() => window.scrollBy(0, window.innerHeight * 2)).catch(() => null);
    await page.waitForTimeout(700);
  }

  const cards = await page.$$eval(CARD_SELECTOR, (els) => els.length).catch(() => 0);
  log('cards matched by CARD_SELECTOR:', cards);
  const anchors = await page.$$eval('a[href*="/catalog/"]', (els) => els.length).catch(() => 0);
  log('anchors with /catalog/:', anchors);
  await shot(page, 'wb-03-after-scroll');

  log(`keeping the window open for ${PAUSE_MS / 1000}s — watch the browser, then it closes`);
  await page.waitForTimeout(PAUSE_MS);
  await shot(page, 'wb-04-final');
} catch (error) {
  log('FAILED:', error?.message || String(error));
  await shot(page, 'wb-99-error');
} finally {
  await browser.close();
}
