import { chromium } from 'playwright';

const BLOCKED_RESOURCE_TYPES = new Set(['image', 'font', 'stylesheet', 'media']);
const BLOCKED_URL_PATTERN = /mc\.yandex|top-fwz1|google-analytics|googletagmanager|facebook\.net|adservice|pixel/i;

const CONTEXT_OPTIONS = {
  locale: 'ru-RU',
  timezoneId: 'Europe/Moscow',
  viewport: { width: 1920, height: 1080 },
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  extraHTTPHeaders: { 'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.8' },
};

const MAX_USES_PER_CONTEXT = Number(process.env.CONTEXT_MAX_USES || 50);
const ACQUIRE_TIMEOUT_MS = Number(process.env.POOL_ACQUIRE_TIMEOUT_MS || 5000);

/**
 * Launches a single Chromium browser and keeps `size` pre-warmed BrowserContexts
 * ready for checkout. Contexts are recycled after MAX_USES_PER_CONTEXT scrapes so
 * that cookie/storage state never grows unbounded.
 */
export async function initPool({ size = 4, log = console } = {}) {
  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--no-sandbox'],
  });

  /** @type {Array<{ context: import('playwright').BrowserContext, uses: number }>} */
  const available = [];
  const inUse = new Set();
  /** @type {Array<{ resolve: Function, reject: Function, timer: NodeJS.Timeout }>} */
  const waiters = [];
  let totalCheckouts = 0;
  let closing = false;

  async function createEntry() {
    const context = await browser.newContext(CONTEXT_OPTIONS);

    await context.route('**/*', (route) => {
      const request = route.request();

      if (BLOCKED_RESOURCE_TYPES.has(request.resourceType())) {
        return route.abort();
      }

      if (BLOCKED_URL_PATTERN.test(request.url())) {
        return route.abort();
      }

      return route.continue();
    });

    return { context, uses: 0 };
  }

  for (let i = 0; i < size; i += 1) {
    available.push(await createEntry());
  }

  log.info?.({ pool_size: size }, 'playwright context pool ready');

  function handOut(entry) {
    totalCheckouts += 1;
    inUse.add(entry);

    let released = false;

    const release = async () => {
      if (released) {
        return;
      }
      released = true;

      inUse.delete(entry);
      entry.uses += 1;

      if (closing) {
        await entry.context.close().catch(() => {});
        return;
      }

      let readyEntry = entry;

      if (entry.uses >= MAX_USES_PER_CONTEXT) {
        await entry.context.close().catch(() => {});
        try {
          readyEntry = await createEntry();
          log.info?.({ recycled_after: entry.uses }, 'context recycled');
        } catch (error) {
          log.error?.({ err: error }, 'failed to recreate context');
          return;
        }
      }

      const waiter = waiters.shift();

      if (waiter) {
        clearTimeout(waiter.timer);
        waiter.resolve(handOut(readyEntry));
        return;
      }

      available.push(readyEntry);
    };

    return { context: entry.context, release };
  }

  return {
    /**
     * Resolves with `{ context, release }`. Waits up to ACQUIRE_TIMEOUT_MS when
     * every context is busy, then rejects.
     */
    async checkout() {
      if (closing) {
        throw new Error('pool is closing');
      }

      const entry = available.shift();

      if (entry) {
        return handOut(entry);
      }

      return new Promise((resolve, reject) => {
        const waiter = {
          resolve,
          reject,
          timer: setTimeout(() => {
            const index = waiters.indexOf(waiter);
            if (index !== -1) {
              waiters.splice(index, 1);
            }
            reject(new Error(`pool acquire timeout after ${ACQUIRE_TIMEOUT_MS}ms`));
          }, ACQUIRE_TIMEOUT_MS),
        };

        waiters.push(waiter);
      });
    },

    stats() {
      return {
        size: available.length + inUse.size,
        available: available.length,
        inUse: inUse.size,
        totalCheckouts,
      };
    },

    async close() {
      closing = true;

      while (waiters.length) {
        const waiter = waiters.shift();
        clearTimeout(waiter.timer);
        waiter.reject(new Error('pool is closing'));
      }

      const entries = [...available, ...inUse];
      available.length = 0;
      inUse.clear();

      await Promise.all(entries.map((entry) => entry.context.close().catch(() => {})));
      await browser.close().catch(() => {});
    },
  };
}
