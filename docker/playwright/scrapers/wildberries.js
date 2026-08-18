import { performance } from 'node:perf_hooks';

const BASE_URL = 'https://www.wildberries.ru';
const MAX_RAW_PAYLOAD_CHARS = 2048;

/**
 * WB search tiles are `<article class="product-card ...">` (legacy `.j-card`);
 * the anchor fallback catches markup drift where only the card link survives.
 */
const CARD_SELECTOR =
  'article.product-card, article[class*="product-card"], .j-card, a[href*="/catalog/"][href*="detail.aspx"]';

/**
 * Cards inside these containers are recommendation carousels / empty-state
 * noise, not search results — they must never be returned as matches.
 */
const NOISE_ANCESTOR_SELECTOR =
  '.content404, .swiper, section[class*="recommendations"], [class*="recommendationsCarousel"]';

/** `/catalog/123456789/detail.aspx` → `123456789`. */
function externalIdFromUrl(url) {
  if (!url) {
    return null;
  }

  const match = String(url).match(/\/catalog\/(\d+)/);

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

  if (value.startsWith('//')) {
    return 'https:' + value;
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

/**
 * Visits the WB homepage first so the search request carries the cookies the
 * site hands out there. Throws `{ code: 'ANTIBOT' }` when the homepage itself
 * is hard-blocked — no point spending the rest of the budget on /search.
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
  await page.waitForTimeout(1200);
  await page.mouse.wheel(0, 300).catch(() => null);
}

/**
 * WB sometimes shows a self-resolving JS challenge ("Проверка браузера").
 * Waits a bounded moment for it to pass; returns true when still blocked.
 */
async function challengePersists(page, budgetMs) {
  const isBlocked = async () => {
    const title = await page.title().catch(() => '');

    return /captcha|проверк|доступ ограничен|antibot|challenge|robot|вы не робот/i.test(title);
  };

  if (!(await isBlocked())) {
    return false;
  }

  const deadline = Date.now() + budgetMs;

  while (Date.now() < deadline) {
    await page.waitForTimeout(800);

    if (!(await isBlocked())) {
      return false;
    }
  }

  return true;
}

/** Reads up to 100 candidate cards off the SERP, flagging recommendation noise. */
async function extractCards(page) {
  return page
    .$$eval(
      CARD_SELECTOR,
      (cards, noiseSelector) =>
        cards.slice(0, 100).map((card) => {
          const text = (selector) => card.querySelector(selector)?.textContent?.trim() ?? null;
          const anchor =
            card.tagName === 'A' && (card.getAttribute('href') ?? '').includes('/catalog/')
              ? card
              : card.querySelector('a[href*="/catalog/"]');
          const image = card.querySelector('img');
          const cardText = card.textContent ?? '';

          return {
            noise: Boolean(card.closest(noiseSelector)),
            nmId: card.getAttribute('data-nm-id') ?? card.getAttribute('data-id') ?? null,
            href: anchor?.getAttribute('href') ?? null,
            title:
              text('[class*="product-card__name"]') ??
              text('span.goods-name') ??
              anchor?.getAttribute('aria-label') ??
              anchor?.getAttribute('title') ??
              image?.getAttribute('alt') ??
              null,
            price:
              text('.price__lower-price') ??
              text('.product-card__price ins') ??
              text('ins[class*="lower-price"]') ??
              (cardText.split('\n').find((line) => /\d[\d\s\u00a0]*₽/.test(line)) ?? null),
            oldPrice:
              text('.price__full-price') ??
              text('.product-card__price del') ??
              text('[class*="full-price"]'),
            imageSrc:
              image?.getAttribute('src') ??
              image?.getAttribute('data-src') ??
              image?.getAttribute('srcset')?.split(' ')[0] ??
              null,
            cardText: cardText.slice(0, 600),
            outerHtml: card.outerHTML.slice(0, 2048),
          };
        }),
      NOISE_ANCESTOR_SELECTOR,
    )
    .catch(() => []);
}

/**
 * Scrapes the Wildberries search results page (DOM extraction; the SERP
 * lazy-loads cards on scroll, so the budget-limited scroll loop nudges it).
 * Throws `{ code: 'ANTIBOT' }` on captcha and `{ code: 'INTERNAL' }` otherwise.
 */
export async function scrape(page, { query, page: pageNum = 1, timeout_ms: timeoutMs = 20000 }) {
  const startedAt = performance.now();
  const elapsed = () => Math.round(performance.now() - startedAt);

  const url =
    BASE_URL +
    '/catalog/0/search.aspx?search=' +
    encodeURIComponent(query ?? '') +
    (pageNum > 1 ? '&page=' + pageNum : '');

  let extractionMode = 'failed';
  let items = [];

  try {
    await warmUpSession(page, Math.min(8000, Math.max(2000, Math.round(timeoutMs / 2))), elapsed);

    // WB's antibot is probabilistic: the exact same request sometimes renders
    // an empty shell (http 498, zero cards) and sometimes the full SERP, so
    // the navigation is retried while the budget allows.
    let rawCards = [];
    let softBlocked = false;

    for (let attempt = 0; attempt < 3 && timeoutMs - elapsed() > 4000; attempt += 1) {
      const response = await page.goto(url, {
        waitUntil: 'domcontentloaded',
        timeout: Math.max(1000, timeoutMs - elapsed() - 1000),
      });

      const status = response?.status() ?? 0;

      if (status === 403 || status === 429) {
        throw { code: 'ANTIBOT', message: `Blocked with http ${status}`, took_ms: elapsed() };
      }

      if (
        /captcha|showcaptcha/i.test(page.url()) ||
        (await challengePersists(page, Math.min(4000, Math.max(500, timeoutMs - elapsed() - 3000))))
      ) {
        throw {
          code: 'ANTIBOT',
          message: `Captcha detected (http ${status}, title "${await page.title().catch(() => '')}")`,
          took_ms: elapsed(),
        };
      }

      // Cards are hydrated client-side, so give them a bounded moment to appear.
      await page
        .waitForSelector(CARD_SELECTOR, {
          timeout: Math.min(5000, Math.max(500, timeoutMs - elapsed() - 2000)),
        })
        .catch(() => null);

      // WB soft-blocks suspicious IPs by rendering "ничего не найдено" for any
      // query while still showing a recommendation carousel below it. Only the
      // carousel cards would match CARD_SELECTOR, so bail out early.
      softBlocked = await page
        .$eval('.content404__title, [class*="content404"]', (el) =>
          /ничего не найдено/i.test(el.textContent ?? ''),
        )
        .catch(() => false);

      if (softBlocked) {
        break;
      }

      // The SERP lazy-loads cards on scroll; nudge it while the budget allows.
      for (let step = 0; step < 2 && timeoutMs - elapsed() > 2500; step += 1) {
        await page.evaluate(() => window.scrollBy(0, window.innerHeight * 2)).catch(() => null);
        await page.waitForTimeout(500);
      }

      rawCards = await extractCards(page);

      if (rawCards.some((card) => !card.noise)) {
        break;
      }
    }

    if (softBlocked) {
      return {
        items: [],
        meta: {
          provider: 'wildberries',
          took_ms: elapsed(),
          extraction_mode: 'soft_blocked',
          total_hint: 0,
        },
      };
    }

    const seenIds = new Set();

    items = rawCards
      .map((card) => {
        if (card.noise) {
          return null;
        }

        const rawHref = card.href ?? '';
        const productUrl = rawHref ? absoluteUrl(rawHref.split('?')[0]) : null;
        const externalId = card.nmId ?? externalIdFromUrl(rawHref);
        const cleanTitle = (card.title ?? '').trim();

        if (!externalId || !cleanTitle || !productUrl) {
          return null;
        }

        const priceAmount = toKopecks(card.price);
        const oldPriceAmount = toKopecks(card.oldPrice);
        // WB prints the rating as `4,8 · 193 оценки` inside the card text.
        const ratingMatch = String(card.cardText).match(/(\d[.,]\d)\s*[·•\n\s]*[\d\s]*(оценок|отзыв)/i);
        const reviewsMatch = String(card.cardText).match(/([\d\s]+)\s*(оценок|отзывов)/i);

        return {
          external_id: String(externalId),
          title: cleanTitle,
          brand: null,
          price_amount: priceAmount,
          old_price_amount: oldPriceAmount === priceAmount ? null : oldPriceAmount,
          currency: 'RUB',
          image_url: absoluteUrl(card.imageSrc),
          product_url: productUrl,
          rating_value: ratingMatch !== null ? Math.min(5, toFloat(ratingMatch[1])) : null,
          rating_count: reviewsMatch !== null ? toInt(reviewsMatch[1]) : null,
          availability_status: priceAmount ? 'in_stock' : null,
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

    if (items.length > 0) {
      extractionMode = 'dom';
    }
  } catch (error) {
    if (error?.code === 'ANTIBOT') {
      throw error;
    }

    throw {
      code: 'INTERNAL',
      message: `wildberries scrape failed: ${error?.message || String(error)}`,
      took_ms: elapsed(),
    };
  }

  return {
    items,
    meta: {
      provider: 'wildberries',
      took_ms: elapsed(),
      extraction_mode: extractionMode,
      total_hint: items.length,
    },
  };
}
