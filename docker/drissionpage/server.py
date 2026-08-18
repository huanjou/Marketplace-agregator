import time
import json
import urllib.parse
from fastapi import FastAPI, HTTPException, Request
from pydantic import BaseModel
from DrissionPage import ChromiumPage, ChromiumOptions
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI()

START_TIME = time.time()

class ScrapeRequest(BaseModel):
    provider: str
    query: str
    page: int = 1
    timeout_ms: int = 15000

# Try to connect to host browser, fallback to internal container browser if not available
try:
    co = ChromiumOptions()
    co.set_address('host.docker.internal:9222')
    page = ChromiumPage(co)
    logger.info("Connected to remote host browser at host.docker.internal:9222")
except Exception as e:
    logger.warning(f"Could not connect to host browser ({e}). Falling back to internal Docker browser.")
    co_fallback = ChromiumOptions().headless(False).set_argument('--no-sandbox').set_argument('--disable-gpu')
    co_fallback.set_user_agent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36')
    page = ChromiumPage(co_fallback)

@app.api_route("/health", methods=["GET", "HEAD"])
def health():
    return {
        "status": "ok",
        "uptime_ms": int((time.time() - START_TIME) * 1000)
    }

@app.post("/scrape")
def scrape(req: ScrapeRequest):
    if req.provider != "ozon":
        raise HTTPException(status_code=500, detail={"error": {"code": "INTERNAL", "message": "Only ozon is supported"}})
    
    start_time = time.time()
    
    url = f"https://www.ozon.ru/search/?text={urllib.parse.quote(req.query)}"
    if req.page > 1:
        url += f"&page={req.page}"
        
    try:
        # Load the page
        page.set.timeouts(page_load=req.timeout_ms / 1000)
        page.get(url)
        
        # Check if we got blocked
        if "captcha" in page.url or "challenge" in page.url:
            page.get_screenshot(path='/app/public', name='debug_captcha.png', full_page=True)
            return {"error": {"code": "ANTIBOT", "message": "Captcha detected by DrissionPage", "took_ms": int((time.time() - start_time) * 1000)}}
            
        # Try to find __NEXT_DATA__
        next_data_elem = page.ele('#__NEXT_DATA__', timeout=3)
        items = []
        extraction_mode = "failed"
        
        def collect_candidates(node, found, depth=0, seen=None):
            if seen is None:
                seen = set()
            if depth > 12 or node is None or not isinstance(node, (dict, list)) or len(found) >= 200:
                return found
                
            node_id = id(node)
            if node_id in seen:
                return found
            seen.add(node_id)
            
            if isinstance(node, list):
                for child in node:
                    collect_candidates(child, found, depth + 1, seen)
                return found
                
            has_id = node.get("sku") or node.get("skuId") or node.get("productId") or node.get("id")
            title = node.get("title") or node.get("name") or node.get("text")
            price = node.get("price") or node.get("finalPrice") or node.get("cardPrice") or node.get("priceValue")
            link = node.get("link") or (node.get("action", {}).get("link") if isinstance(node.get("action"), dict) else None) or node.get("url") or node.get("deeplink")
            
            if has_id and isinstance(title, str) and len(title) > 2 and (price is not None or link):
                found.append(node)
                
            for val in node.values():
                if isinstance(val, (dict, list)):
                    collect_candidates(val, found, depth + 1, seen)
                    
            return found
        
        if next_data_elem:
            try:
                data = json.loads(next_data_elem.text)
                
                expanded = []
                # Find widgetStates
                buckets = [
                    data.get("props", {}).get("pageProps", {}).get("state", {}).get("data", {}).get("widgetStates", {}),
                    data.get("props", {}).get("pageProps", {}).get("initialState", {}).get("widgetStates", {}),
                    data.get("widgetStates", {})
                ]
                
                for bucket in buckets:
                    if isinstance(bucket, dict):
                        for val in bucket.values():
                            if isinstance(val, str):
                                try:
                                    expanded.append(json.loads(val))
                                except:
                                    pass
                            else:
                                expanded.append(val)
                
                if not expanded:
                    expanded.append(data)
                    
                candidates = []
                for bucket in expanded:
                    collect_candidates(bucket, candidates)
                    
                seen_ids = set()
                for entry in candidates:
                    link = entry.get("link") or (entry.get("action", {}).get("link") if isinstance(entry.get("action"), dict) else None) or entry.get("url") or entry.get("deeplink")
                    title = str(entry.get("title") or entry.get("name") or entry.get("text") or "").strip()
                    ext_id = str(entry.get("sku") or entry.get("skuId") or entry.get("productId") or entry.get("id") or "")
                    
                    if not ext_id or not title or not link:
                        continue
                        
                    if ext_id in seen_ids:
                        continue
                    seen_ids.add(ext_id)
                    
                    price_val = entry.get("price") or entry.get("finalPrice") or entry.get("cardPrice") or entry.get("priceValue")
                    price_str = str(price_val).replace('\u2009', '').replace('\xa0', '').replace(' ', '').replace('₽', '').replace(',', '.')
                    
                    try:
                        price_amount = int(float(price_str) * 100)
                    except:
                        price_amount = None
                        
                    items.append({
                        "external_id": ext_id,
                        "title": title,
                        "brand": entry.get("brand") or entry.get("brandName") or entry.get("vendor"),
                        "price_amount": price_amount,
                        "old_price_amount": None,
                        "currency": "RUB",
                        "image_url": None,
                        "product_url": f"https://www.ozon.ru{link}" if link.startswith("/") else link,
                        "rating_value": None,
                        "rating_count": None,
                        "availability_status": "in_stock" if price_amount else None,
                        "stock_quantity": None,
                        "raw_payload": None
                    })
                
                if items:
                    extraction_mode = "next_data"
            except Exception as e:
                logger.error(f"Failed to parse NEXT_DATA: {e}")
        
        if not items:
            # Save screenshot for debugging empty results
            try:
                page.get_screenshot(path='/app/public', name='debug_empty.png')
                with open('/app/public/debug_empty.html', 'w', encoding='utf-8') as f:
                    f.write(page.html)
            except Exception as e:
                import traceback
                print(traceback.format_exc(), flush=True)
                logger.error(f"Failed to save screenshot: {e}")
                
        took_ms = int((time.time() - start_time) * 1000)
        
        return {
            "items": items,
            "meta": {
                "provider": "ozon",
                "took_ms": took_ms,
                "extraction_mode": extraction_mode,
                "total_hint": len(items)
            }
        }
        
    except Exception as e:
        logger.error(f"DrissionPage Error: {e}")
        return {"error": {"code": "INTERNAL", "message": str(e), "took_ms": int((time.time() - start_time) * 1000)}}
