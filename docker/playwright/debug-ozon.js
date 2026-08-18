/**
 * Headed-mode debug runner for the Ozon scraper.
 *
 * Opens a REAL visible browser window with the exact same stealth/context
 * settings as the pool so we can watch what Ozon's antibot actually does.
 *
 * Usage (inside the playwright container):
 *   node debug-ozon.js
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

const TILE_SELECTOR =
  'div[data-widget="searchResultsV2"] .tile-root, .tile-root, .tile-hover-target';

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
  log('STEP 1: homepage warm-up -> https://www.ozon.ru/');
  const homeResp = await page.goto('https://www.ozon.ru/', {
    waitUntil: 'domcontentloaded',
    timeout: 20000,
  });
  log('homepage status:', homeResp?.status());
  log('homepage title:', await page.title().catch(() => ''));
  log('homepage final url:', page.url());
  await page.waitForTimeout(2000);
  await shot(page, '01-homepage');

  const searchUrl = 'https://www.ozon.ru/search/?text=' + encodeURIComponent(QUERY);
  log('STEP 2: search ->', searchUrl);
  const searchResp = await page.goto(searchUrl, { waitUntil: 'commit', timeout: 20000 });
  log('search status:', searchResp?.status());
  await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => null);
  log('search title:', await page.title().catch(() => ''));
  log('search final url:', page.url());
  await shot(page, '02-search-commit');

  const hasNextData = await page.$('script#__NEXT_DATA__');
  log('__NEXT_DATA__ present:', Boolean(hasNextData));

  const tiles = await page
    .waitForSelector(TILE_SELECTOR, { timeout: 8000 })
    .then(() => page.$$eval(TILE_SELECTOR, (els) => els.length))
    .catch(() => 0);
  log('tiles matched by TILE_SELECTOR:', tiles);
  await shot(page, '03-search-after-wait');

  log(`keeping the window open for ${PAUSE_MS / 1000}s — watch the browser, then it closes`);
  await page.waitForTimeout(PAUSE_MS);
  await shot(page, '04-final');
} catch (error) {
  log('FAILED:', error?.message || String(error));
  await shot(page, '99-error');
} finally {
  await browser.close();
}
