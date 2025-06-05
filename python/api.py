from fastapi import FastAPI, Request, HTTPException
import httpx
from datetime import datetime, timedelta
from collections import deque
import asyncio
import logging
from sqlalchemy.orm import Session
from database import SessionLocal, engine
from models import Base, RequestLog, IpCache

logging.basicConfig(level=logging.INFO, format="%(asctime)s | %(levelname)s | %(message)s")
app = FastAPI()

Base.metadata.create_all(bind=engine)

# Configuration
GAODE_KEY = "mykey"
WEATHERAPI_KEY = "mykey"
CACHE_DURATION = timedelta(minutes=10)
RATE_LIMIT_DURATION = timedelta(minutes=1)
MAX_REQUESTS_PER_MINUTE = 10

request_times = deque()
lock = asyncio.Lock()


# -------------------------------
# Utilities
# -------------------------------

def extract_client_ip(request: Request) -> str:
    return (
        request.headers.get("x-forwarded-for", "").split(",")[0].strip()
        or request.headers.get("x-real-ip")
        or request.client.host
    )

def prune_old_requests(now: datetime):
    while request_times and now - request_times[0] > RATE_LIMIT_DURATION:
        request_times.popleft()

def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

def get_cached_response(db: Session, ip: str, now: datetime):
    ip_entry = db.query(IpCache).filter_by(ip=ip).first()
    if ip_entry and now - ip_entry.cached_at < CACHE_DURATION:
        logging.info(f"Cache hit for {ip}")
        return ip_entry.response_data
    return None

async def check_rate_limit(now: datetime):
    async with lock:
        prune_old_requests(now)
        if len(request_times) >= MAX_REQUESTS_PER_MINUTE:
            logging.warning("Rate limit exceeded.")
            raise HTTPException(status_code=403, detail="Too many requests")
        request_times.append(now)

def log_request(db: Session, ip: str, timestamp, result=None, error=None, from_cache=0):
    log = RequestLog(ip=ip, timestamp=timestamp, from_cache=from_cache)
    if result:
        log.response_data = result
    if error:
        log.error = error
    db.add(log)
    db.commit()

def update_cache(db: Session, ip: str, now: datetime, result: dict):
    db.merge(IpCache(ip=ip, cached_at=now, response_data=result))
    db.commit()

# -------------------------------
# API Integration
# -------------------------------

async def fetch_from_amap(client: httpx.AsyncClient, ip: str):
    try:
        resp = await client.get(f"https://restapi.amap.com/v3/ip?key={GAODE_KEY}&ip={ip}")
        resp.raise_for_status()
        data = resp.json()
        city = data.get("city", "").strip()
        adcode = data.get("adcode", "").strip()
        if not city or not adcode:
            logging.info(f"AMap returned no city for IP: {ip}")
            return None
        logging.info(f"AMap returned city {city}, adcode {adcode} for IP {ip}")
        weather = await client.get(f"https://restapi.amap.com/v3/weather/weatherInfo?key={GAODE_KEY}&city={adcode}")
        weather.raise_for_status()
        w_data = weather.json().get("lives", [{}])[0]
        return {
            "city": city,
            "weather": w_data.get("weather"),
            "temperature": w_data.get("temperature")
        }
    except httpx.RequestError as e:
        logging.error(f"AMap request failed: {e}")
        return None
    except Exception as e:
        logging.warning(f"AMap weather parsing error: {e}")
        return None

async def fetch_from_fallback(client: httpx.AsyncClient, ip: str):
    try:
        ipinfo = await client.get(f"https://ipinfo.io/widget/demo/{ip}")
        ipinfo_data = ipinfo.json()
        city = ipinfo_data.get("data", {}).get("city", "")
        if not city:
            logging.warning(f"ipinfo fallback failed: no city")
            return None
        weather_resp = await client.get(
            f"https://api.weatherapi.com/v1/current.json?key={WEATHERAPI_KEY}&q={city}&aqi=no"
        )
        weather_resp.raise_for_status()
        wa_data = weather_resp.json()
        return {
            "city": city,
            "weather": wa_data["current"]["condition"]["text"],
            "temperature": wa_data["current"]["temp_c"]
        }
    except Exception as e:
        logging.error(f"Fallback weather failed: {e}")
        return None

# -------------------------------
# Main Route
# -------------------------------

@app.get("/weather")
async def weather_proxy(request: Request):
    now = datetime.utcnow()
    client_ip = extract_client_ip(request)
    logging.info(f"Received request from {client_ip}")
    db: Session = next(get_db())

    # Check cache
    cached = get_cached_response(db, client_ip, now)
    if cached:
        log_request(db, client_ip, now, result=cached, from_cache=1)
        return cached

    # Rate limit check
    await check_rate_limit(now)

    result = None
    async with httpx.AsyncClient(timeout=5) as client:
        # Try AMap first
        result = await fetch_from_amap(client, client_ip)

        # If failed or no city, try fallback
        if not result:
            result = await fetch_from_fallback(client, client_ip)

    if result:
        async with lock:
            update_cache(db, client_ip, now, result)
            log_request(db, client_ip, now, result=result, from_cache=0)
        return result

    error_msg = "Unable to retrieve weather from both sources"
    log_request(db, client_ip, now, error=error_msg)
    raise HTTPException(status_code=502, detail=error_msg)
