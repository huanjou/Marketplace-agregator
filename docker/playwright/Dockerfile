FROM mcr.microsoft.com/playwright:v1.50.0-noble

# The base image ships the browser binaries in /ms-playwright but NOT the
# `playwright` npm package, so it is installed here pinned to the image version
# (1.50.0 -> chromium-1155) with the browser download skipped: no duplication.
ENV NODE_ENV=production \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    PORT=3000

WORKDIR /app

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev; else npm install --omit=dev; fi

COPY server.js pool.js ./
COPY scrapers ./scrapers

EXPOSE 3000

HEALTHCHECK --interval=15s --timeout=5s --retries=3 --start-period=20s \
    CMD wget -qO- http://localhost:3000/health || exit 1

CMD ["node", "server.js"]
