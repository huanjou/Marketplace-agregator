import { performance } from 'node:perf_hooks';

const BASE_URL = 'https://market.yandex.ru';
const MAX_RAW_PAYLOAD_CHARS = 2048;

/** Hosts an externally supplied SERP URL may ever point at. */
const ALLOWED_HOSTS = new Set(['market.yandex.ru']);

/**
 * Validates an optional AI-built SERP URL: https only, allow-listed host,
 * SERP path prefix. Anything suspicious returns null and the caller falls
 * back to composing its own plain-text search link.
 */
function resolveOverrideUrl(candidate) {
  if (!candidate || typeof candidate !== 'string') {
    return null;
  }

  let parsed;

  try {
    parsed = new URL(candidate);
  } catch {
    return null;
  }

  if (parsed.protocol !== 'https:' || !ALLOWED_HOSTS.has(parsed.hostname)) {
    return null;
  }

  if (!parsed.pathname.startsWith('/search')) {
    return null;
  }

  return parsed.toString();
}

/** Verified against the live SERP: snippets carry `data-zone-name="productSnippet"`. */
const SNIPPET_SELECTOR =
  '[data-zone-name="productSnippet"], [data-auto="snippet-list"] article, [data-baobab-name="$serpProduct"], [data-zone-name="snippet-card"]';

function absoluteUrl(url) {
  if (!url) {
    return null;
  }

  const value = String(url);

  if (value.startsWith('http://') || value.startsWith('https://')) {
    return value;
  }

  if (value.startsWith('//')) {
    return 'https:' + value;
  }

  return BASE_URL + (value.startsWith('/') ? value : '/' + value);
}

/** `/product--slug/12345`, `/product/12345` or `/card/slug/12345` → `12345`. */
function externalIdFromUrl(url) {
  if (!url) {
    return null;
  }

  const value = String(url);
  const match =
    value.match(/\/product(?:--[^/?#]*)?\/(\d+)/) ?? value.match(/\/card\/[^/?#]+\/(\d+)/);

  return match ? match[1] : null;
}

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

/** Yandex embeds `{"type":"offer","wareId":...,"modelId":...,"marketSku":...}` per snippet. */
function parseZoneData(raw) {
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function availabilityFrom(text) {
  if (!text) {
    return null;
  }

  const value = String(text).toLowerCase();

  if (/нет в наличии|нет в продаже|закончил|снят с продажи|out of stock/.test(value)) {
    return 'out_of_stock';
  }

  if (/в наличии|в корзину|купить|доставка|in stock/.test(value)) {
    return 'in_stock';
  }

  return null;
}

/**
 * Unwraps RSC flight chunks (`self.__next_f.push([1,"..."])`) into a single
 * de-escaped text blob that can be mined for product-shaped JSON.
 */
function decodeFlightChunks(rawScripts) {
  const chunks = [];
  const pattern = /self\.__next_f\.push\(\[\s*\d+\s*,\s*("(?:\\.|[^"\\])*")\s*\]\)/g;
  let match;

  while ((match = pattern.exec(rawScripts)) !== null) {
    try {
      chunks.push(JSON.parse(match[1]));
    } catch {
      // Chunk is not a plain string literal — skip it.
    }
  }

  return chunks.join('');
}

/** Returns the balanced `{...}` substring starting at `start`, or null. */
function balancedObjectAt(text, start, limit = 20000) {
  let depth = 0;
  let inString = false;
  let escaped = false;

  for (let i = start; i < text.length && i - start < limit; i += 1) {
    const char = text[i];

    if (inString) {
      if (escaped) {
        escaped = false;
      } else if (char === '\\') {
        escaped = true;
      } else if (char === '"') {
        inString = false;
      }

      continue;
    }

    if (char === '"') {
      inString = true;
    } else if (char === '{') {
      depth += 1;
    } else if (char === '}') {
      depth -= 1;

      if (depth === 0) {
        return text.slice(start, i + 1);
      }
    }
  }

  return null;
}

/**
 * Finds JSON objects in `text` that contain one of the product markers by walking
 * backwards from every marker hit to the closest parsable `{`.
 */
function mineProductObjects(text, markers, maxObjects = 120) {
  const results = [];

  for (const marker of markers) {
    let from = 0;

    while (results.length < maxObjects) {
      const hit = text.indexOf(marker, from);

      if (hit === -1) {
        break;
      }

      from = hit + marker.length;

      let cursor = hit;
      let attempts = 0;

      while (cursor >= 0 && attempts < 25) {
        cursor = text.lastIndexOf('{', cursor);

        if (cursor === -1) {
          break;
        }

        const candidate = balancedObjectAt(text, cursor);

        if (candidate && candidate.length > marker.length && cursor + candidate.length > hit) {
          try {
            results.push(JSON.parse(candidate));
            break;
          } catch {
            // Not valid on its own — widen the window.
          }
        }

        cursor -= 1;
        attempts += 1;
      }
    }
  }

  return results;
}

function normalizeJsonEntry(entry) {
  const rawUrl =
    entry.urls?.encrypted ??
    entry.urls?.direct ??
    entry.url ??
    entry.link ??
    entry.offer?.urls?.encrypted ??
    null;
  const productUrl = absoluteUrl(rawUrl);
  const externalId = String(
    entry.productId ??
      entry.skuId ??
      entry.sku ??
      entry.id ??
      entry.entityId ??
      externalIdFromUrl(productUrl) ??
      '',
  );
  const title = String(
    entry.titles?.raw ?? entry.titles?.highlighted ?? entry.title ?? entry.name ?? '',
  ).trim();

  if (!externalId || !title || !productUrl) {
    return null;
  }

  const priceAmount = toKopecks(
    entry.prices?.value ??
      entry.prices?.currentPrice ??
      entry.price?.value ??
      entry.price ??
      entry.offer?.price?.value ??
      null,
  );
  const oldPriceAmount = toKopecks(
    entry.prices?.discount?.oldMin ??
      entry.prices?.base ??
      entry.price?.oldValue ??
      entry.oldPrice ??
      null,
  );

  return {
    external_id: externalId,
    title,
    brand: entry.vendor?.name ?? entry.brand?.name ?? entry.brand ?? entry.vendorName ?? null,
    price_amount: priceAmount,
    old_price_amount: oldPriceAmount,
    currency: 'RUB',
    image_url: absoluteUrl(
      entry.pictures?.[0]?.original?.url ??
        entry.pictures?.[0]?.url ??
        entry.picture?.original?.url ??
        entry.image ??
        null,
    ),
    product_url: productUrl,
    rating_value: toFloat(entry.rating?.value ?? entry.ratingValue ?? entry.rating ?? null),
    rating_count: toInt(
      entry.rating?.count ?? entry.opinions ?? entry.reviewsCount ?? entry.ratingCount ?? null,
    ),
    availability_status:
      availabilityFrom(entry.availability ?? entry.offer?.availability ?? null) ??
      (priceAmount ? 'in_stock' : null),
    stock_quantity: null,
    raw_payload: clipRaw(entry),
  };
}

/**
 * Scrapes Yandex Market search results. Flight-JSON first, DOM selectors as fallback.
 * Throws `{ code: 'ANTIBOT' }` on captcha and `{ code: 'INTERNAL' }` otherwise.
 */
export async function scrape(page, { query, page: pageNum = 1, timeout_ms: timeoutMs = 8000, url: overrideUrl = null }) {
  const startedAt = performance.now();
  const elapsed = () => Math.round(performance.now() - startedAt);

  const url =
    resolveOverrideUrl(overrideUrl) ??
    BASE_URL +
      '/search?text=' +
      encodeURIComponent(query ?? '') +
      (pageNum > 1 ? '&page=' + pageNum : '');

  let extractionMode = 'failed';
  let items = [];

  try {
    // `commit` resolves on response headers, so a block is classified without
    // waiting for the whole SPA shell to load.
    const response = await page.goto(url, {
      waitUntil: 'commit',
      timeout: Math.max(1000, timeoutMs - 1000),
    });

    const status = response?.status() ?? 0;

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
      /captcha|showcaptcha|checkcaptcha/i.test(currentUrl) ||
      /access denied|captcha|antibot|challenge|подтвердите|robot|вы не робот/i.test(title)
    ) {
      throw {
        code: 'ANTIBOT',
        message: `Captcha detected (http ${status}, title "${title}")`,
        took_ms: elapsed(),
      };
    }

    const flightData = await page
      .$$eval('script', (scripts) =>
        scripts
          .map((s) => s.textContent)
          .filter((t) => t && t.includes('self.__next_f.push'))
          .join('\n'),
      )
      .catch(() => '');

    const seenIds = new Set();

    if (flightData) {
      const decoded = decodeFlightChunks(flightData) || flightData;
      const candidates = mineProductObjects(decoded, [
        '"entity":"product"',
        '"titles":{',
        '"productId":',
        '"skuId":',
      ]);

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
        extractionMode = 'flight_json';
      }
    }

    // Snippets are hydrated client-side, so give them a bounded moment to appear.
    await page
      .waitForSelector(SNIPPET_SELECTOR, {
        timeout: Math.min(5000, Math.max(500, timeoutMs - elapsed() - 800)),
      })
      .catch(() => null);

    // The SERP lazy-loads snippets on scroll; nudge it while the budget allows.
    for (let step = 0; step < 3 && timeoutMs - elapsed() > 2500; step += 1) {
      await page.evaluate(() => window.scrollBy(0, window.innerHeight * 2)).catch(() => null);
      await page.waitForTimeout(400);
    }

    const rawCards = await page
      .$$eval(
        SNIPPET_SELECTOR,
        (cards) =>
          cards.slice(0, 60).map((card) => {
            const text = (selector) => card.querySelector(selector)?.textContent?.trim() ?? null;
            const anchor =
              card.querySelector('a[data-auto="snippet-link"]') ??
              card.querySelector('a[href*="/card/"]') ??
              card.querySelector('a[href*="/product"]') ??
              card.querySelector('a[href]');
            const image = card.querySelector('img');

            return {
              href: anchor?.getAttribute('href') ?? null,
              zoneData: card.getAttribute('data-zone-data') ?? null,
              productId:
                card.getAttribute('data-product-id') ?? card.getAttribute('data-id') ?? null,
              title:
                text('[data-auto="snippet-title"]') ??
                text('[data-zone-name="title"]') ??
                text('h3') ??
                image?.getAttribute('alt') ??
                null,
              price:
                text('[data-auto="snippet-price-current"]') ??
                text('[data-auto="price-value"]') ??
                text('[data-auto="mainPrice"]') ??
                null,
              oldPrice:
                text('[data-auto="snippet-price-old"]') ?? text('[data-auto="old-price"]') ?? null,
              imageSrc: image?.getAttribute('src') ?? null,
              ratingText:
                text('[data-auto="rating-badge-value"]') ??
                text('[data-auto="reviews"] span') ??
                null,
              reviewsText: text('[data-auto="reviews-count"]') ?? text('[data-auto="reviews"]'),
              brand: text('[data-auto="brand-name"]') ?? null,
              cardText: (text('[data-auto="snippet-title"]') ?? '') + ' ' + (text('[data-auto="delivery-wrapper"]') ?? ''),
              outerHtml: card.outerHTML.slice(0, 2048),
            };
          }),
      )
      .catch(() => []);

    const domItems = rawCards
      .map((card) => {
        const productUrl = absoluteUrl(card.href);
        const zoneData = parseZoneData(card.zoneData);
        const externalId =
          zoneData?.marketSku ??
          zoneData?.modelId ??
          zoneData?.wareId ??
          card.productId ??
          externalIdFromUrl(card.href);
        const cleanTitle = (card.title ?? '').trim();

        if (!externalId || !cleanTitle || !productUrl) {
          return null;
        }

        const priceAmount = toKopecks(card.price);
        const ratingValue = toFloat(card.ratingText);

        return {
          external_id: String(externalId),
          title: cleanTitle,
          brand: card.brand,
          price_amount: priceAmount,
          old_price_amount: toKopecks(card.oldPrice),
          currency: 'RUB',
          image_url: absoluteUrl(card.imageSrc),
          product_url: productUrl,
          rating_value: ratingValue !== null && ratingValue <= 5 ? ratingValue : null,
          rating_count: toInt(card.reviewsText),
          availability_status:
            availabilityFrom(card.cardText) ?? (priceAmount ? 'in_stock' : null),
          stock_quantity: null,
          raw_payload: clipRaw(card.outerHtml),
        };
      })
      .filter((item) => {
        if (!item || seenIds.has(item.external_id)) {
          return false;
        }
        seenIds.add(item.external_id);

        return true;
      });

    items = items.concat(domItems);

    if (domItems.length > 0) {
      extractionMode = extractionMode === 'flight_json' ? 'flight_json+dom' : 'dom';
    }
  } catch (error) {
    if (error?.code === 'ANTIBOT') {
      throw error;
    }

    throw {
      code: 'INTERNAL',
      message: `yandex_market scrape failed: ${error?.message || String(error)}`,
      took_ms: elapsed(),
    };
  }

  return {
    items,
    meta: {
      provider: 'yandex_market',
      took_ms: elapsed(),
      extraction_mode: extractionMode,
      total_hint: items.length,
    },
  };
}
