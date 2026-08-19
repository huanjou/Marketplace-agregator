"""
Ozon scraping service backed by Camoufox (anti-detect Firefox).

Ozon's ABT antibot layer rejects every Chromium fingerprint we tested — even
through a clean RU residential IP the challenge page answers "Выключите VPN".
Camoufox passes the same challenge, so this service keeps one persistent
Camoufox browser behind the configured egress proxy (PROXY_URL), warms the
session on the homepage once, and then scrapes public search pages.

All Playwright calls run on a single dedicated worker thread (the sync API is
bound to the thread that launched the browser); FastAPI endpoints hand jobs
to it through a queue.

The HTTP contract is unchanged: POST /scrape {provider, query, page,
timeout_ms} -> {items, meta}, GET /health. Items are snake_case dicts,
JSON-first (__NEXT_DATA__) with a DOM fallback — same shape the Playwright
service returns for the other providers.
"""

import logging
import os
import queue
import threading
import time
import urllib.parse

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI()

START_TIME = time.time()
BASE_URL = "https://www.ozon.ru"

# A session that has been idle this long gets re-warmed on the homepage before
# the next search, because Ozon lets challenge cookies lapse.
SESSION_MAX_IDLE_SECONDS = 600

# After this many searches the session is rotated proactively (fresh browser
# context), because Ozon escalates to a visual captcha after sustained
# scraping from one session.
MAX_SEARCHES_PER_SESSION = 6

JOB_TIMEOUT_SECONDS = 90


class ScrapeRequest(BaseModel):
    provider: str
    query: str
    page: int = 1
    timeout_ms: int = 30000


def parse_proxy(url: str):
    """http://user:pass@host:port -> playwright-style proxy dict."""
    if not url:
        return None
    try:
        rest = url.split("://", 1)[1]
        creds, hostport = rest.split("@", 1)
        user, pwd = creds.split(":", 1)
        return {"server": "http://" + hostport, "username": user, "password": pwd}
    except Exception:
        logger.warning("Could not parse PROXY_URL; going out directly.")
        return None


PROXY = parse_proxy(os.environ.get("PROXY_URL", ""))


class ScrapeError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class BrowserWorker:
    """Owns the Camoufox browser; every Playwright call runs in this thread."""

    def __init__(self):
        self._queue = queue.Queue()
        self._thread = threading.Thread(target=self._loop, name="camoufox-worker", daemon=True)
        self._extraction_js = ""
        self._ctx = None
        self._browser = None
        self._page = None
        self._session_ok_at = 0.0
        self._searches = 0

    # -- worker thread -------------------------------------------------------

    def _loop(self):
        while True:
            job = self._queue.get()
            fn, args, out = job
            try:
                result = fn(*args)
                out.put((True, result))
            except ScrapeError as e:
                out.put((False, {"code": e.code, "message": str(e)}))
            except Exception as e:  # noqa: BLE001 — surface anything as INTERNAL
                logger.exception("Worker job failed")
                out.put((False, {"code": "INTERNAL", "message": str(e)}))

    def _launch(self):
        from camoufox.sync_api import Camoufox

        logger.info("Launching Camoufox browser (proxy=%s)", "on" if PROXY else "off")
        self._ctx = Camoufox(headless="virtual", **({"proxy": PROXY} if PROXY else {}))
        self._browser = self._ctx.__enter__()
        self._page = None
        self._session_ok_at = 0.0
        self._searches = 0

    def _teardown(self):
        for closer in (
            lambda: self._page and self._page.close(),
            lambda: self._browser and self._browser.close(),
            lambda: self._ctx and self._ctx.__exit__(None, None, None),
        ):
            try:
                closer()
            except Exception:
                pass
        self._ctx = self._browser = self._page = None

    def _restart_session(self, reason: str):
        logger.info("Rotating browser session (%s)", reason)
        self._teardown()
        self._launch()

    def _get_page(self):
        if self._browser is None:
            self._launch()
        if self._page is None or self._page.is_closed():
            self._page = self._browser.new_page()
            self._page.set_default_navigation_timeout(30000)
        return self._page

    @staticmethod
    def _looks_challenged(title: str, status) -> bool:
        if status in (403, 429):
            return True
        lowered = (title or "").lower()
        return any(marker in lowered for marker in ("antibot", "captcha", "нет соединения"))

    @staticmethod
    def _is_soft_error(page) -> bool:
        """Http 200 shell whose search widget died server-side."""
        try:
            return "Произошла ошибка" in (page.content() or "")
        except Exception:
            return False

    def _warm_session(self, page, budget_ms: int) -> None:
        """
        Visits the homepage and lets the ABT challenge resolve. Two flows:
        the plain JS challenge (403 commit, /abt/result post, reload ~6-10s
        later) and the escalated slide-puzzle captcha, which we solve by
        template-matching the piece outline and dragging the slider.
        Raises ScrapeError(ANTIBOT) when neither resolves inside the budget.
        """
        deadline = time.time() + budget_ms / 1000
        # `domcontentloaded`: the SERP lazy-loads media, so `load` can stall.
        response = page.goto(BASE_URL + "/", timeout=min(20000, budget_ms), wait_until="domcontentloaded")
        status = response.status if response else 0
        page.wait_for_timeout(6000)

        if self._looks_challenged(page.title(), status):
            if not self._resolve_challenge(page, deadline):
                title = page.title()
                raise ScrapeError(
                    "ANTIBOT",
                    f"Antibot challenge did not resolve (http {status}, title \"{title}\")",
                )
            status = 200

        # Behave like a reader before navigating on.
        try:
            page.mouse.wheel(0, 300)
        except Exception:
            pass
        self._session_ok_at = time.time()
        logger.info("Ozon session warmed (http %s, title \"%s\")", status, page.title()[:60])

    def _resolve_challenge(self, page, deadline: float) -> bool:
        """True when the page is back on the real site before the deadline."""
        slider = None
        try:
            slider = page.wait_for_selector("#captcha-container", timeout=3000)
        except Exception:
            slider = None

        if slider is not None:
            logger.info("Slide-puzzle captcha detected; solving")
            solved = self._solve_slider(page)
            if not solved:
                return False
            # The widget reloads the page itself after a successful solve.
            for _ in range(10):
                if time.time() > deadline:
                    break
                page.wait_for_timeout(1000)
                if not self._looks_challenged(page.title(), None):
                    return True
            return not self._looks_challenged(page.title(), None)

        # Plain JS challenge: let it post /abt/result, then reload once.
        if time.time() > deadline - 8:
            return False
        page.reload(timeout=15000, wait_until="domcontentloaded")
        page.wait_for_timeout(4000)
        return not self._looks_challenged(page.title(), None)

    def _solve_slider(self, page) -> bool:
        """
        Ozon's escalated captcha is a slide puzzle: #image carries dark piece
        outlines, #puzzle is the draggable piece (PNG with alpha). We locate
        the outline whose border matches the piece silhouette in the piece's
        row, then drag #slider by the CSS delta with human-like motion.
        """
        import io
        import random
        import urllib.request

        import numpy as np
        from PIL import Image

        try:
            scale = float(
                page.eval_on_selector(
                    "#captcha",
                    "el => (getComputedStyle(el).getPropertyValue('--scale') || '1').trim()",
                )
                or 1.0
            )
            img_url = page.eval_on_selector("#image", "el => el.src")
            pz_url = page.eval_on_selector("#puzzle", "el => el.src")
            pz_top = float(page.eval_on_selector("#puzzle", "el => parseFloat(el.style.top) || 0"))
            pz_left = float(page.eval_on_selector("#puzzle", "el => parseFloat(el.style.left) || 0"))
        except Exception as e:
            logger.warning("slider: cannot read widget geometry: %s", e)
            return False

        try:
            with urllib.request.urlopen(img_url, timeout=10) as r:
                bg = np.asarray(Image.open(io.BytesIO(r.read())).convert("L"), dtype=np.float32)
            with urllib.request.urlopen(pz_url, timeout=10) as r:
                pz = np.asarray(Image.open(io.BytesIO(r.read())).convert("RGBA"), dtype=np.float32)
        except Exception as e:
            logger.warning("slider: cannot download captcha images: %s", e)
            return False

        mask = pz[:, :, 3] > 100
        ys, xs = np.where(mask)
        if len(xs) == 0:
            return False
        piece = mask[ys.min() : ys.max() + 1, xs.min() : xs.max() + 1]
        h, w = piece.shape

        # Ring = silhouette border (dilate/erode via shifts, radius 2).
        def dilate(m, r):
            out = m.copy()
            for _ in range(r):
                out = out | np.roll(out, 1, 0) | np.roll(out, -1, 0) | np.roll(out, 1, 1) | np.roll(out, -1, 1)
            return out

        def erode(m, r):
            out = m.copy()
            for _ in range(r):
                out = out & np.roll(out, 1, 0) & np.roll(out, -1, 0) & np.roll(out, 1, 1) & np.roll(out, -1, 1)
            return out

        ring = dilate(piece, 2) & ~erode(piece, 2)
        ring_px = np.argwhere(ring)  # (y, x) offsets
        if len(ring_px) == 0:
            return False

        y0 = int(round(pz_top / scale))
        if y0 < 0 or y0 + h > bg.shape[0]:
            return False

        # Outlines are darker than the blue backdrop; dilate for tolerance.
        # The 25th percentile separates outline strokes from background detail
        # across lighting variants (verified offline on captured widgets).
        best_s, best_score = None, -1.0
        for dy in range(-2, 3, 2):
            if y0 + dy < 0 or y0 + dy + h > bg.shape[0]:
                continue
            band = bg[y0 + dy : y0 + dy + h, :]
            dark = dilate(band < np.percentile(band, 25), 2)
            for s in range(0, band.shape[1] - w, 2):
                score = dark[ring_px[:, 0], s + ring_px[:, 1]].mean()
                if score > best_score:
                    best_score, best_s = score, s

        if best_s is None or best_score < 0.6:
            logger.warning("slider: no matching outline (score=%s)", best_score)
            return False

        delta_css = (best_s - xs.min() - pz_left / scale) * scale
        logger.info("slider: delta=%.1fpx score=%.2f", delta_css, best_score)

        box = page.query_selector("#slider").bounding_box()
        if not box:
            return False
        sx, sy = box["x"] + box["width"] / 2, box["y"] + box["height"] / 2

        page.mouse.move(sx, sy)
        page.wait_for_timeout(random.randint(100, 300))
        page.mouse.down()
        steps = random.randint(20, 28)
        for i in range(1, steps + 1):
            t = i / steps
            ease = 1 - (1 - t) ** 3
            jitter = 0 if i == steps else random.uniform(-1.5, 1.5)
            page.mouse.move(sx + delta_css * ease + jitter, sy + random.uniform(-1, 1))
            page.wait_for_timeout(random.randint(8, 26))
        page.mouse.move(sx + delta_css + 2, sy)
        page.wait_for_timeout(random.randint(40, 90))
        page.mouse.move(sx + delta_css, sy)
        page.wait_for_timeout(random.randint(30, 60))
        page.mouse.up()
        return True

    def _scrape(self, query: str, page_num: int, budget_ms: int) -> dict:
        start = time.time()
        budget_ms = max(budget_ms, 10000)

        def elapsed_ms():
            return int((time.time() - start) * 1000)

        def remaining_ms():
            return budget_ms - elapsed_ms()

        # Rotate proactively before Ozon escalates to a visual captcha.
        if self._searches >= MAX_SEARCHES_PER_SESSION:
            self._restart_session("session search quota reached")

        page = self._get_page()

        needs_warm = (
            self._session_ok_at == 0.0
            or (time.time() - self._session_ok_at) > SESSION_MAX_IDLE_SECONDS
        )
        if needs_warm:
            self._warm_session(page, max(15000, budget_ms // 2))

        url = f"{BASE_URL}/search/?text={urllib.parse.quote(query)}"
        if page_num > 1:
            url += f"&page={page_num}"

        response = page.goto(url, timeout=max(5000, remaining_ms()), wait_until="domcontentloaded")
        status = response.status if response else 0
        page.wait_for_timeout(3000)

        title = page.title()
        if self._looks_challenged(title, status):
            # Session lapsed mid-flight: one warm retry, then give up.
            logger.warning("Search hit challenge (http %s); re-warming", status)
            self._warm_session(page, max(15000, remaining_ms()))
            response = page.goto(url, timeout=max(5000, remaining_ms()), wait_until="domcontentloaded")
            status = response.status if response else 0
            page.wait_for_timeout(3000)
            title = page.title()
            if self._looks_challenged(title, status):
                raise ScrapeError("ANTIBOT", f"Blocked with http {status} (title \"{title}\")")

        try:
            extracted = page.evaluate(self._extraction_js)
        except Exception as e:
            logger.error("Extraction failed: %s", e)
            extracted = None

        items = (extracted or {}).get("items") or []
        mode = (extracted or {}).get("mode") or "failed"

        if not items and self._is_soft_error(page):
            # Ozon occasionally serves its soft "Произошла ошибка" shell with
            # http 200 when the search widget fails server-side; the page
            # itself suggests a refresh, and a reload usually fixes it.
            logger.warning("Soft error page detected; reloading once")
            try:
                page.reload(timeout=max(5000, remaining_ms()), wait_until="domcontentloaded")
                page.wait_for_timeout(3000)
                extracted = page.evaluate(self._extraction_js)
                items = (extracted or {}).get("items") or []
                mode = (extracted or {}).get("mode") or mode
            except Exception as e:
                logger.warning("Soft-error reload failed: %s", e)

        if not items:
            try:
                page.screenshot(path="/app/public/debug_empty.png", full_page=True)
                with open("/app/public/debug_empty.html", "w", encoding="utf-8") as f:
                    f.write(page.content())
            except Exception:
                pass
        else:
            self._session_ok_at = time.time()
            self._searches += 1

        return {
            "items": items,
            "meta": {
                "provider": "ozon",
                "took_ms": elapsed_ms(),
                "extraction_mode": mode,
                "total_hint": len(items),
            },
        }

    def _health(self) -> dict:
        return {
            "status": "ok",
            "uptime_ms": int((time.time() - START_TIME) * 1000),
            "session_warmed": self._session_ok_at > 0,
            "session_searches": self._searches,
            "proxy": bool(PROXY),
        }

    # -- API called from request threads -------------------------------------

    def submit(self, fn, *args):
        out = queue.Queue()
        self._queue.put((fn, args, out))
        ok, payload = out.get(timeout=JOB_TIMEOUT_SECONDS)
        if ok:
            return payload
        raise ScrapeError(payload.get("code", "INTERNAL"), payload.get("message", "unknown error"))

    def scrape(self, query: str, page_num: int, budget_ms: int) -> dict:
        return self.submit(self._scrape, query, page_num, budget_ms)

    def health(self) -> dict:
        return self.submit(self._health)


WORKER = BrowserWorker()


@app.on_event("startup")
def _startup():
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ozon_extract.js")
    with open(path, "r", encoding="utf-8") as f:
        WORKER._extraction_js = f.read()
    logger.info("ozon extraction script loaded (%d chars)", len(WORKER._extraction_js))
    WORKER._thread.start()


@app.api_route("/health", methods=["GET", "HEAD"])
def health():
    return {
        "status": "ok",
        "uptime_ms": int((time.time() - START_TIME) * 1000),
        "proxy": bool(PROXY),
    }


@app.post("/scrape")
def scrape(req: ScrapeRequest):
    if req.provider != "ozon":
        raise HTTPException(
            status_code=500,
            detail={"error": {"code": "INTERNAL", "message": "Only ozon is supported"}},
        )

    started = time.time()
    try:
        return WORKER.scrape(req.query, req.page, req.timeout_ms)
    except ScrapeError as e:
        return {
            "error": {
                "code": e.code,
                "message": str(e),
                "took_ms": int((time.time() - started) * 1000),
            }
        }
    except queue.Empty:
        return {
            "error": {
                "code": "INTERNAL",
                "message": "scraper worker timed out",
                "took_ms": int((time.time() - started) * 1000),
            }
        }
