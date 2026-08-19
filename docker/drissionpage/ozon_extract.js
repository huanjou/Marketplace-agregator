/**
 * In-page extraction for Ozon search results, evaluated via Camoufox/Playwright.
 * Ported 1:1 from docker/playwright/scrapers/ozon.js: JSON-first
 * (`__NEXT_DATA__` widgetStates), DOM tile fallback. Returns {items, mode}.
 */
(async () => {
  const BASE_URL = 'https://www.ozon.ru';
  const MAX_RAW_PAYLOAD_CHARS = 2048;
  const TILE_SELECTOR =
    'div[data-widget="searchResultsV2"] .tile-root, .tile-root, .tile-hover-target';

  // `/product/{slug}-{id}/` — the trailing run of digits in the path segment.
  const externalIdFromUrl = (url) => {
    if (!url) return null;
    const match = String(url).match(/\/product\/[^/?#]*?(\d+)(?=[/?#]|$)/);
    return match ? match[1] : null;
  };

  /**
   * Modern SERPs obfuscate most class names, but the title span still carries
   * the stable tsBody500Medium typography class. The image anchor must NOT be
   * mined for text: it wraps the promo badges ("Осталось 10 шт", "Новинка"),
   * whose concatenated text is longer than short product titles.
   */
  const extractTitle = (tile, anchor) => {
    const spanText = tile
      .querySelector('.tsBody500Medium, [class*="tsBody500"]')
      ?.textContent?.trim();
    if (spanText && spanText.length > 2) return spanText;

    for (const node of tile.querySelectorAll('a[href*="/product/"]')) {
      if (node.querySelector('img')) continue; // image+badge anchor, not the title
      const value = node.textContent.trim();
      if (value.length > 3 && !/^\d[\d\s\u00a0,.₽%]*$/.test(value)) return value;
    }
    return anchor?.getAttribute('title') ?? tile.querySelector('img')?.getAttribute('alt') ?? null;
  };

  const absoluteUrl = (url) => {
    if (!url) return null;
    const value = String(url);
    if (value.startsWith('http://') || value.startsWith('https://')) return value;
    return BASE_URL + (value.startsWith('/') ? value : '/' + value);
  };

  const toKopecks = (value) => {
    if (value === null || value === undefined) return null;
    if (typeof value === 'number' && Number.isFinite(value)) return Math.round(value * 100);
    const digits = String(value)
      .replace(/\u00a0/g, ' ')
      .replace(/[^\d,.]/g, '')
      .replace(/\s/g, '')
      .replace(',', '.');
    if (!digits) return null;
    const amount = Number.parseFloat(digits);
    return Number.isFinite(amount) ? Math.round(amount * 100) : null;
  };

  const toFloat = (value) => {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    const match = String(value ?? '').replace(',', '.').match(/\d+(\.\d+)?/);
    return match ? Number.parseFloat(match[0]) : null;
  };

  const toInt = (value) => {
    if (typeof value === 'number' && Number.isFinite(value)) return Math.round(value);
    const digits = String(value ?? '').replace(/\u00a0/g, '').match(/\d[\d\s]*/);
    return digits ? Number.parseInt(digits[0].replace(/\s/g, ''), 10) : null;
  };

  const clipRaw = (value) => {
    if (typeof value === 'string') return value.slice(0, MAX_RAW_PAYLOAD_CHARS);
    try {
      return JSON.stringify(value).slice(0, MAX_RAW_PAYLOAD_CHARS);
    } catch {
      return null;
    }
  };

  const availabilityFrom = (text) => {
    if (!text) return null;
    const value = String(text).toLowerCase();
    if (/нет в наличии|закончил|распродан|out of stock|unavailable/.test(value)) return 'out_of_stock';
    if (/в наличии|в корзину|купить|in stock|available/.test(value)) return 'in_stock';
    return null;
  };

  const collectProductCandidates = (node, found = [], depth = 0, seen = new Set()) => {
    if (depth > 12 || node === null || typeof node !== 'object' || found.length >= 200) return found;
    if (seen.has(node)) return found;
    seen.add(node);

    if (Array.isArray(node)) {
      for (const child of node) collectProductCandidates(child, found, depth + 1, seen);
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
      if (value && typeof value === 'object') collectProductCandidates(value, found, depth + 1, seen);
    }
    return found;
  };

  const expandWidgetStates = (root) => {
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
      if (fallback) expanded.push(fallback);
    }
    return expanded;
  };

  const normalizeJsonEntry = (entry) => {
    const productUrl = absoluteUrl(entry.link ?? entry.action?.link ?? entry.url ?? entry.deeplink ?? null);
    const externalId = String(entry.sku ?? entry.skuId ?? entry.productId ?? entry.id ?? externalIdFromUrl(productUrl) ?? '');
    const title = String(entry.title ?? entry.name ?? entry.text ?? '').trim();
    if (!externalId || !title || !productUrl) return null;

    const priceAmount = toKopecks(entry.price ?? entry.finalPrice ?? entry.cardPrice ?? entry.priceValue ?? null);
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
        availabilityFrom(entry.availability ?? entry.stockStatus ?? null) ?? (priceAmount ? 'in_stock' : null),
      stock_quantity: null,
      raw_payload: clipRaw(entry),
    };
  };

  // --- JSON-first: __NEXT_DATA__ -------------------------------------------
  let items = [];
  let mode = 'failed';

  const nextDataEl = document.querySelector('script#__NEXT_DATA__');
  if (nextDataEl) {
    try {
      const parsed = JSON.parse(nextDataEl.textContent);
      const candidates = [];
      for (const bucket of expandWidgetStates(parsed)) collectProductCandidates(bucket, candidates);

      const seenIds = new Set();
      items = candidates
        .map((entry) => normalizeJsonEntry(entry))
        .filter((item) => {
          if (!item || seenIds.has(item.external_id)) return false;
          seenIds.add(item.external_id);
          return true;
        });

      if (items.length > 0) mode = 'next_data';
    } catch {
      // Malformed embedded JSON — fall through to the DOM strategy.
    }
  }

  // --- DOM fallback ----------------------------------------------------------
  if (items.length === 0) {
    // Tiles are hydrated client-side; poll briefly instead of a hard wait.
    const pollUntil = Date.now() + 5000;
    let tiles = document.querySelectorAll(TILE_SELECTOR);
    while (tiles.length === 0 && Date.now() < pollUntil) {
      await new Promise((resolve) => setTimeout(resolve, 250));
      tiles = document.querySelectorAll(TILE_SELECTOR);
    }

    const rawTiles = [...tiles].slice(0, 60).map((tile) => {
      const text = (selector) => tile.querySelector(selector)?.textContent?.trim() ?? null;
      const anchor = tile.querySelector('a[href*="/product/"]') ?? tile.closest('a[href*="/product/"]');
      const image = tile.querySelector('img');
      const priceNodes = [...tile.querySelectorAll('span, div')]
        .map((node) => node.textContent?.trim() ?? '')
        .filter((value) => /^\d[\d\s\u00a0]*(,\d+)?\s*₽$/.test(value));

      return {
        href: anchor?.getAttribute('href') ?? null,
        sku: tile.getAttribute('data-sku') ?? anchor?.getAttribute('data-sku') ?? null,
        title: extractTitle(tile, anchor),
        prices: priceNodes,
        imageSrc: image?.getAttribute('src') ?? null,
        ratingText: text('[class*="tsBodyMBold"]') ?? text('[data-widget="webReviewTabs"]'),
        tileText: tile.textContent?.slice(0, 400) ?? '',
        outerHtml: tile.outerHTML.slice(0, 2048),
      };
    });

    const seenIds = new Set();
    items = rawTiles
      .map((tile) => {
        const productUrl = absoluteUrl(tile.href);
        const externalId = tile.sku ?? externalIdFromUrl(tile.href);
        const cleanTitle = (tile.title ?? '').trim();
        if (!externalId || !cleanTitle || !productUrl) return null;

        const prices = (tile.prices ?? []).map((value) => toKopecks(value)).filter(Boolean);
        const priceAmount = prices.length ? Math.min(...prices) : null;
        const oldPriceAmount = prices.length > 1 ? Math.max(...prices) : null;
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
        if (!item || seenIds.has(item.external_id)) return false;
        seenIds.add(item.external_id);
        return true;
      });

    if (items.length > 0) mode = 'dom';
  }

  return { items, mode };
})()
