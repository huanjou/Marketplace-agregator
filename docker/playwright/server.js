import Fastify from 'fastify';
import pino from 'pino';
import { performance } from 'node:perf_hooks';

import { initPool } from './pool.js';

const startTime = performance.now();

const PORT = Number(process.env.PORT || 3000);
const POOL_SIZE = Number(process.env.POOL_SIZE || 4);
const LOG_LEVEL = process.env.LOG_LEVEL || 'info';

const logger = pino({ level: LOG_LEVEL });

const app = Fastify({ logger });

/** Lazily imported scraper modules, keyed by provider code. */
const scrapers = new Map();

async function loadScraper(provider) {
  if (!scrapers.has(provider)) {
    scrapers.set(provider, await import('./scrapers/' + provider + '.js'));
  }

  return scrapers.get(provider);
}

const pool = await initPool({ size: POOL_SIZE, log: logger });

app.get('/health', async () => ({
  status: 'ok',
  pool: pool.stats(),
  uptime_ms: Math.round(performance.now() - startTime),
}));

const scrapeSchema = {
  body: {
    type: 'object',
    required: ['provider', 'query'],
    properties: {
      provider: { type: 'string', enum: ['ozon', 'yandex_market', 'wildberries'] },
      query: { type: 'string', minLength: 0, maxLength: 200 },
      page: { type: 'integer', minimum: 1, default: 1 },
      timeout_ms: { type: 'integer', minimum: 1000, maximum: 30000, default: 8000 },
    },
  },
};

app.post('/scrape', { schema: scrapeSchema }, async (request, reply) => {
  const { provider, query, page: pageNum = 1, timeout_ms: timeoutMs = 8000 } = request.body;
  const startedAt = performance.now();
  const elapsed = () => Math.round(performance.now() - startedAt);

  let scraper;

  try {
    scraper = await loadScraper(provider);
  } catch (error) {
    request.log.error({ err: error, provider }, 'unknown scraper module');

    return reply.code(500).send({
      error: { code: 'INTERNAL', message: `scraper for provider "${provider}" is not available` },
    });
  }

  let checkout;

  try {
    checkout = await pool.checkout();
  } catch (error) {
    request.log.warn({ err: error, provider }, 'context pool exhausted');

    return reply.code(503).send({
      error: { code: 'POOL_BUSY', message: error.message, took_ms: elapsed() },
    });
  }

  const { context, release } = checkout;
  const browserPage = await context.newPage();

  try {
    const result = await Promise.race([
      scraper.scrape(browserPage, { query, page: pageNum, timeout_ms: timeoutMs }),
      new Promise((_resolve, rejectRace) => {
        setTimeout(
          () => rejectRace({ code: 'TIMEOUT', message: `scrape exceeded ${timeoutMs}ms` }),
          timeoutMs,
        ).unref?.();
      }),
    ]);

    request.log.info(
      { provider, query, page: pageNum, items: result.items.length, meta: result.meta },
      'scrape finished',
    );

    return reply.code(200).send(result);
  } catch (error) {
    const code = error?.code;

    if (code === 'ANTIBOT') {
      request.log.warn({ provider, query }, 'antibot protection triggered');

      return reply.code(502).send({
        error: {
          code: 'ANTIBOT',
          message: error.message || 'Captcha detected',
          took_ms: error.took_ms ?? elapsed(),
        },
      });
    }

    if (code === 'TIMEOUT') {
      request.log.warn({ provider, query, timeout_ms: timeoutMs }, 'scrape timed out');

      return reply.code(504).send({
        error: { code: 'TIMEOUT', message: error.message, took_ms: elapsed() },
      });
    }

    request.log.error({ err: error, provider, query }, 'scrape failed');

    return reply.code(500).send({
      error: {
        code: 'INTERNAL',
        message: error?.message || String(error),
        took_ms: elapsed(),
      },
    });
  } finally {
    await browserPage.close().catch(() => {});
    await release();
  }
});

let shuttingDown = false;

async function shutdown(signal) {
  if (shuttingDown) {
    return;
  }
  shuttingDown = true;

  logger.info({ signal }, 'shutting down');

  await pool.close().catch((error) => logger.error({ err: error }, 'pool close failed'));
  await app.close().catch((error) => logger.error({ err: error }, 'fastify close failed'));

  process.exit(0);
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

try {
  await app.listen({ host: '0.0.0.0', port: PORT });
  logger.info({ port: PORT, pool_size: POOL_SIZE }, 'playwright scraper service listening');
} catch (error) {
  logger.error({ err: error }, 'failed to start');
  await pool.close().catch(() => {});
  process.exit(1);
}
