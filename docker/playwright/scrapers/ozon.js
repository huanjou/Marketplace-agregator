import { performance } from 'node:perf_hooks';

const BASE_URL = 'https://www.ozon.ru';
const MAX_RAW_PAYLOAD_CHARS = 2048;

const TILE_SELECTOR =
  'div[data-widget="searchResultsV2"] .tile-root, .tile-root, .tile-hover-target';

/** Extracts the trailing numeric id from `/product/{slug}-{id}/`. */
function externalIdFromUrl(url) {
  if (!url) {
    return null;
  }

  const match = String(url).match(/\/product\/(?:[^/?#]*?-)?(\d+)/);

  return match ? match[1] : null;
}

function absoluteUrl(url) {
  if (!url) {
    return null;
  }

  const value = String(url);

  if (value.startsWith('http://') || value.startsWith('https://')) {
    return value;
  }

  return BASE_URL + (value.startsWith('/') ? value : '/' + value);
}

/** Converts a RUB price (`"45 990 ₽"`, `45990`, `"45 990,50"`) into kopecks. */
function toKopecks(value) {
  if (value === null || value === undefined) {
    return null;
  }

  if (typeof value === 'number' && Number.isFinite(value)) {
    return Math.round(value * 100);
  }

  const digits = String(value)
    .replace(/\u00a0/g, ' ')
    .replace(/[^\d,.]/g, '')
    .replace(/\s/g, '')
    .replace(',', '.');

  if (!digits) {
    return null;
  }

  const amount = Number.parseFloat(digits);

  return Number.isFinite(amount) ? Math.round(amount * 100) : null;
}

function toFloat(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  const match = String(value ?? '').replace(',', '.').match(/\d+(\.\d+)?/);

  return match ? Number.parseFloat(match[0]) : null;
}

function toInt(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return Math.round(value);
  }

  const digits = String(value ?? '').replace(/\u00a0/g, '').match(/\d[\d\s]*/);

  return digits ? Number.parseInt(digits[0].replace(/\s/g, ''), 10) : null;
}

function clipRaw(value) {
  if (typeof value === 'string') {
    return value.slice(0, MAX_RAW_PAYLOAD_CHARS);
  }

  try {
    return JSON.stringify(value).slice(0, MAX_RAW_PAYLOAD_CHARS);
  } catch {
    return null;
  }
}

function availabilityFrom(text) {
  if (!text) {
    return null;
  }

  const value = String(text).toLowerCase();

  if (/нет в наличии|закончил|распродан|out of stock|unavailable/.test(value)) {
    return 'out_of_stock';
  }

  if (/в наличии|в корзину|купить|in stock|available/.test(value)) {
    return 'in_stock';
  }

  return null;
}

/**
 * Walks an arbitrary JSON tree and collects objects that look like product tiles
 * (they carry an sku/id plus a title and something price-shaped).
 */
function collectProductCandidates(node, found = [], depth = 0, seen = new Set()) {
  if (depth > 12 || node === null || typeof node !== 'object' || found.length >= 200) {
    return found;
  }

  if (seen.has(node)) {
    return found;
  }
  seen.add(node);

  if (Array.isArray(node)) {
    for (const child of node) {
      collectProductCandidates(child, found, depth + 1, seen);
    }

    return found;
  }

  const hasId = node.sku ?? node.skuId ?? node.productId ?? node.id;
  const title = node.title ?? node.name ?? node.text;
  const price = node.price ?? node.finalPrice ?? node.cardPrice ?? node.priceValue;
  const link = node.link ?? node.action?.link ?? node.url ?? node.deeplink;

  if (hasId && typeof title === 'string' && title.length > 2 && (price !== undefined || link)) {
    found.push(node);
  }

  for (const value of Object.values(node)) {
    if (value && typeof value === 'object') {
      collectProductCandidates(value, found, depth + 1, seen);
    }
  }

  return found;
}

/** Ozon serialises widget payloads as JSON strings inside `widgetStates`. */
function expandWidgetStates(root) {
  const expanded = [];
  const buckets = [
    root?.props?.pageProps?.state?.data?.widgetStates,
    root?.props?.pageProps?.initialState?.widgetStates,
    root?.widgetStates,
  ].filter(Boolean);

  for (const bucket of buckets) {
    for (const value of Object.values(bucket)) {
      if (typeof value !== 'string') {
        expanded.push(value);
        continue;
      }

      try {
        expanded.push(JSON.parse(value));
      } catch {
        // Not a JSON payload — nothing to mine here.
      }
    }
  }

  if (expanded.length === 0) {
    const fallback = root?.props?.pageProps?.initialState ?? root?.props?.pageProps ?? root;

    if (fallback) {
      expanded.push(fallback);
    }
  }

  return expanded;
}

function normalizeJsonEntry(entry) {
  const productUrl = absoluteUrl(
    entry.link ?? entry.action?.link ?? entry.url ?? entry.deeplink ?? null,
  );
  const externalId = String(
    entry.sku ?? entry.skuId ?? entry.productId ?? entry.id ?? externalIdFromUrl(productUrl) ?? '',
  );
  const title = String(entry.title ?? entry.name ?? entry.text ?? '').trim();

  if (!externalId || !title || !productUrl) {
    return null;
  }

  const priceAmount = toKopecks(
    entry.price ?? entry.finalPrice ?? entry.cardPrice ?? entry.priceValue ?? null,
  );
  const oldPriceAmount = toKopecks(entry.originalPrice ?? entry.oldPrice ?? entry.basePrice ?? null);

  return {
    external_id: externalId,
    title,
    brand: entry.brand ?? entry.brandName ?? entry.vendor ?? null,
    price_amount: priceAmount,
    old_price_amount: oldPriceAmount,
    currency: 'RUB',
    image_url: absoluteUrl(entry.image ?? entry.images?.[0]?.link ?? entry.images?.[0] ?? null),
    product_url: productUrl,
    rating_value: toFloat(entry.rating ?? entry.ratingValue ?? null),
    rating_count: toInt(entry.commentsCount ?? entry.ratingCount ?? entry.reviewsCount ?? null),
    availability_status:
      availabilityFrom(entry.availability ?? entry.stockStatus ?? null) ??
      (priceAmount ? 'in_stock' : null),
    stock_quantity: null,
    raw_payload: clipRaw(entry),
  };
}

/**
 * Visits the Ozon homepage first so the search request carries the cookies the
 * antibot layer hands out there. Throws `{ code: 'ANTIBOT' }` when the homepage
 * itself is blocked — no point spending the rest of the budget on /search.
 */
async function warmUpSession(page, budgetMs, elapsed) {
  const response = await page.goto(BASE_URL + '/', {
    waitUntil: 'domcontentloaded',
    timeout: budgetMs,
  });

  const status = response?.status() ?? 0;

  if (status === 403 || status === 429) {
    throw {
      code: 'ANTIBOT',
      message: `Blocked with http ${status} (homepage warm-up)`,
      took_ms: elapsed(),
    };
  }

  // Let the client-side bootstrap run and look like a reader, not a fetcher.
  await page.waitForTimeout(1500);
  await page.mouse.wheel(0, 300).catch(() => null);
}

/**
 * Scrapes the Ozon search results page. JSON-first (`__NEXT_DATA__`), DOM fallback.
 * Throws `{ code: 'ANTIBOT' }` on captcha and `{ code: 'INTERNAL' }` for anything else.
 */
export async function scrape(page, { query, page: pageNum = 1, timeout_ms: timeoutMs = 8000 }) {
  const startedAt = performance.now();
  const elapsed = () => Math.round(performance.now() - startedAt);

  const url =
    BASE_URL +
    '/search/?text=' +
    encodeURIComponent(query ?? '') +
    (pageNum > 1 ? '&page=' + pageNum : '');

  let extractionMode = 'failed';
  let items = [];

  try {
    // Half the budget at most, so a slow homepage never starves the search step.
    await warmUpSession(page, Math.min(10000, Math.max(2000, Math.round(timeoutMs / 2))), elapsed);

    // `commit` resolves as soon as response headers arrive, so a challenge page is
    // classified immediately instead of hanging until the navigation timeout.
    const response = await page.goto(url, {
      waitUntil: 'commit',
      timeout: Math.max(1000, timeoutMs - elapsed() - 1000),
    });

    const status = response?.status() ?? 0;

    // Ozon answers datacenter IPs with HTTP 403 + "Antibot Challenge Page".
    if (status === 403 || status === 429) {
      throw { code: 'ANTIBOT', message: `Blocked with http ${status}`, took_ms: elapsed() };
    }

    await page
      .waitForLoadState('domcontentloaded', {
        timeout: Math.min(6000, Math.max(500, timeoutMs - elapsed() - 1000)),
      })
      .catch(() => null);

    const currentUrl = page.url();
    const title = await page.title().catch(() => '');

    if (
      /captcha|challenge/i.test(currentUrl) ||
      /access denied|captcha|antibot|challenge|доступ ограничен|robot/i.test(title)
    ) {
      throw {
        code: 'ANTIBOT',
        message: `Captcha detected (http ${status}, title "${title}")`,
        took_ms: elapsed(),
      };
    }

    const nextData = await page
      .$eval('script#__NEXT_DATA__', (el) => el.textContent)
      .catch(() => null);

    if (nextData) {
      try {
        const parsed = JSON.parse(nextData);
        const candidates = [];

        for (const bucket of expandWidgetStates(parsed)) {
          collectProductCandidates(bucket, candidates);
        }

        const seenIds = new Set();

        items = candidates
          .map((entry) => normalizeJsonEntry(entry))
          .filter((item) => {
            if (!item || seenIds.has(item.external_id)) {
              return false;
            }
            seenIds.add(item.external_id);

            return true;
          });

        if (items.length > 0) {
          extractionMode = 'next_data';
        }
      } catch (error) {
        if (error?.code === 'ANTIBOT') {
          throw error;
        }
        // Malformed embedded JSON — fall through to the DOM strategy.
      }
    }

    if (items.length === 0) {
      // Tiles are hydrated client-side, so give them a bounded moment to appear.
      await page
        .waitForSelector(TILE_SELECTOR, {
          timeout: Math.min(5000, Math.max(500, timeoutMs - elapsed() - 800)),
        })
        .catch(() => null);

      const rawTiles = await page
        .$$eval(
          TILE_SELECTOR,
          (tiles) =>
            tiles.slice(0, 60).map((tile) => {
              const text = (selector) => tile.querySelector(selector)?.textContent?.trim() ?? null;
              const anchor =
                tile.querySelector('a[href*="/product/"]') ??
                tile.closest('a[href*="/product/"]');
              const image = tile.querySelector('img');
              const priceNodes = [...tile.querySelectorAll('span, div')]
                .map((node) => node.textContent?.trim() ?? '')
                .filter((value) => /^\d[\d\s\u00a0]*(,\d+)?\s*₽$/.test(value));

              return {
                href: anchor?.getAttribute('href') ?? null,
                sku: tile.getAttribute('data-sku') ?? anchor?.getAttribute('data-sku') ?? null,
                title:
                  text('span.tsBody500Medium') ??
                  text('[class*="tsBody500Medium"]') ??
                  anchor?.getAttribute('title') ??
                  image?.getAttribute('alt') ??
                  null,
                prices: priceNodes,
                imageSrc: image?.getAttribute('src') ?? null,
                ratingText: text('[class*="tsBodyMBold"]') ?? text('[data-widget="webReviewTabs"]'),
                tileText: tile.textContent?.slice(0, 400) ?? '',
                outerHtml: tile.outerHTML.slice(0, 2048),
              };
            }),
        )
        .catch(() => []);

      const seenIds = new Set();

      items = rawTiles
        .map((tile) => {
          const productUrl = absoluteUrl(tile.href);
          const externalId = tile.sku ?? externalIdFromUrl(tile.href);
          const cleanTitle = (tile.title ?? '').trim();

          if (!externalId || !cleanTitle || !productUrl) {
            return null;
          }

          const prices = (tile.prices ?? []).map((value) => toKopecks(value)).filter(Boolean);
          const priceAmount = prices.length ? Math.min(...prices) : null;
          const oldPriceAmount =
            prices.length > 1 ? Math.max(...prices) : null;
          const ratingValue = toFloat(tile.ratingText);

          return {
            external_id: String(externalId),
            title: cleanTitle,
            brand: null,
            price_amount: priceAmount,
            old_price_amount: oldPriceAmount === priceAmount ? null : oldPriceAmount,
            currency: 'RUB',
            image_url: absoluteUrl(tile.imageSrc),
            product_url: productUrl,
            rating_value: ratingValue !== null && ratingValue <= 5 ? ratingValue : null,
            rating_count: null,
            availability_status: availabilityFrom(tile.tileText) ?? (priceAmount ? 'in_stock' : null),
            stock_quantity: null,
            raw_payload: clipRaw(tile.outerHtml),
          };
        })
        .filter((item) => {
          if (!item || seenIds.has(item.external_id)) {
            return false;
          }
          seenIds.add(item.external_id);

          return true;
        });

      if (items.length > 0) {
        extractionMode = 'dom';
      }
    }
  } catch (error) {
    if (error?.code === 'ANTIBOT') {
      throw error;
    }

    throw {
      code: 'INTERNAL',
      message: `ozon scrape failed: ${error?.message || String(error)}`,
      took_ms: elapsed(),
    };
  }

  return {
    items,
    meta: {
      provider: 'ozon',
      took_ms: elapsed(),
      extraction_mode: extractionMode,
      total_hint: items.length,
    },
  };
}
