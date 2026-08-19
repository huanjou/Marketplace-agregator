FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1
ENV DEBIAN_FRONTEND=noninteractive

# Xvfb provides the virtual display Camoufox renders into (headless="virtual");
# the GTK/GLib set is required by the bundled Firefox itself; wget serves the
# compose healthcheck.
RUN for i in 1 2 3 4 5; do apt-get update && apt-get install -y --no-install-recommends \
    xvfb \
    xauth \
    wget \
    libgtk-3-0 \
    libdbus-glib-1-2 \
    libxt6 \
    libasound2 \
    libpango-1.0-0 \
    libcairo2 \
    libx11-xcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxrandr2 \
    libgbm1 \
    libdrm2 \
    fonts-dejavu-core && break || sleep 15; done \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Download the anti-detect Firefox build at image build time so the container
# starts without needing network access to the release CDN.
RUN for i in 1 2 3; do python3 -m camoufox fetch && break || sleep 15; done

COPY . .

CMD xvfb-run -a uvicorn server:app --host 0.0.0.0 --port 8000 > /tmp/uvicorn.log 2>&1 & tail -f /tmp/uvicorn.log
