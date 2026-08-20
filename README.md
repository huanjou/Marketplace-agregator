# Агрегатор маркетплейсов

Сервис агрегации товарной выдачи Ozon, Wildberries и Яндекс Маркета:
публичный поиск по всем площадкам сразу, админ-панель на Filament с логами
синхронизации и управлением провайдерами.

Стек: Laravel 12, Filament 3, Livewire 3, PostgreSQL, Redis, Docker Compose.
Скрапинг: Playwright-пул браузеров (WB / Яндекс Маркет) и Camoufox-бэкенд
(Ozon, обход ABT-антибота + автосолвер капчи-слайдера).

## Интерфейс главной страницы
<img width="2530" height="1299" alt="image" src="https://github.com/user-attachments/assets/e71c2012-638c-4fc0-b4ed-381b3dbd17b8" />

## Админ панель
<img width="1731" height="1289" alt="image" src="https://github.com/user-attachments/assets/912e8c3d-3999-4621-a3dc-b5762e9da4a8" />
<img width="1412" height="863" alt="image" src="https://github.com/user-attachments/assets/1e880ecf-d78d-47b1-8169-19ab6f1ef425" />


## Требования

- Docker и Docker Compose (весь стек, включая PHP, живёт в контейнерах);
- на хосте ничего ставить не нужно: `php`/`composer`/`node` вызываются через `make …` внутри контейнеров.

## Быстрый старт

Одной командой (идемпотентно, можно перезапускать):

```bash
./start.sh            # .env + up + key:generate + migrate + seed
./start.sh --build    # то же с пересборкой образов
```

Или вручную:

```bash
cp .env.example .env

make up                                   # собрать и поднять все контейнеры
make artisan cmd='key:generate'           # сгенерировать APP_KEY в .env
make migrate                              # накатить миграции
make seed                                 # админ + справочник провайдеров
```

После этого:

| Что | Где |
|---|---|
| Публичный поиск (без авторизации) | http://localhost:8080/ |
| Админ-панель (логи, провайдеры) | http://localhost:8080/admin |
| Логин админа из сидера | `admin@example.com` / `password` |

## Состав стека

| Контейнер | Роль |
|---|---|
| `app` | PHP-FPM (Laravel) |
| `nginx` | веб-фронт, порт 8080 |
| `postgres` | PostgreSQL (основное хранилище) |
| `redis` | кэш поисковой выдачи, очереди |
| `queue` | воркер очередей (health-check провайдеров, прогрев кэша) |
| `scheduler` | `schedule:work` (периодические задачи) |
| `playwright` | пул браузеров: скраперы WB и Яндекс Маркета |
| `drissionpage` | Camoufox-бэкенд скрапера Ozon (порт 8000) |

## Ключевые переменные окружения

Полный список — в `.env.example`. Самое важное:

- `OZON_PROXY_URL` — **липкий российский резидентный прокси** для Ozon
  (`http://user:pass@host:port`). Антибот Ozon привязывает challenge-куки к
  IP, поэтому линия должна держать один IP всю сессию — ротация на каждый
  запрос ломает скрапинг. Без прокси Ozon почти всегда отдаёт 403/капчу.
- `PROXY_URL` — опциональный прокси для пула Playwright (WB/Яндекс по умолчанию ходят напрямую).
- `MARKETPLACE_OZON_ENABLED`, `MARKETPLACE_YANDEX_MARKET_ENABLED`,
  `MARKETPLACE_WILDBERRIES_ENABLED`, `MARKETPLACE_FAKE_ENABLED` — включение провайдеров.
- `*_CACHE_TTL_SECONDS` — TTL кэша выдачи по каждому провайдеру и общий
  `marketplace.search.cache_ttl_seconds`: весь отсортированный результат
  кэшируется в Redis под ключом без номера страницы, поэтому пагинация
  режется из памяти без повторных запросов к скраперам.
- `PLAYWRIGHT_URL`, `DRISSIONPAGE_URL` (в `config/marketplace.php`) — адреса скрапящих сервисов.

## Команды (Makefile)

```bash
make up / down / restart / build / logs
make shell                 # шелл контейнера app
make artisan cmd='route:list'
make composer cmd='install'
make migrate / make seed
make test                  # PHPUnit
make playwright-restart    # перезапустить скрапер-пул
```

## Как устроен поиск

1. `ProductSearchService` нормализует запрос и смотрит кэш (Redis).
2. Промах — fan-out по включённым провайдерам (`app/Services/Providers/*`),
   каждый скрапит свою площадку и возвращает весь набор совпадений.
3. `ResultAggregator` дедуплицирует и сортирует объединённый набор; страница
   режется из полного набора при чтении (`ProductSearchResult::forPage`).
4. Результат кэшируется целиком и зеркалируется в таблицы `search_caches` /
   `search_cache_items` (наблюдаемость в админке).
5. Каждый поиск пишется в `sync_logs` — виден только в админке после авторизации.

## Тесты

```bash
make test
```

Юнит- и фич-тесты пайплайна работают на фикстурах (`tests/Fixtures`);
тесты, помеченные как live-network (смоки админки, пагинация по реальным
площадкам), могут падать без сети/прокси — это ожидаемо.

## Структура

```
app/Services/Providers/   провайдеры маркетплейсов + мапперы
app/Services/             агрегация, дедупликация, кэш поиска
app/Filament/             админ-панель (ресурсы, дашборд поиска)
app/Livewire/             публичный поиск на главной
docker/playwright/        скраперы WB / Яндекс Маркета (Node + Playwright)
docker/drissionpage/      скрапер Ozon (Python + Camoufox, solver капчи)
tests/                    PHPUnit: unit + feature + фикстуры
```
