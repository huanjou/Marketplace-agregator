FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1
ENV DEBIAN_FRONTEND=noninteractive

# Install Chromium and Xvfb (for headless display)
# Install Chromium and Xvfb (for headless display)
RUN for i in 1 2 3 4 5; do apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    chromium-driver \
    xvfb \
    xauth \
    wget && break || sleep 15; done \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# Run with Xvfb to support fallback browser if remote browser is not available
CMD xvfb-run -a uvicorn server:app --host 0.0.0.0 --port 8000 > /tmp/uvicorn.log 2>&1 & tail -f /tmp/uvicorn.log
