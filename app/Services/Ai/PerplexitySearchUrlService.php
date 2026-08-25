<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Http\Clients\PerplexityClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI-assisted search URL builder, powered by Perplexity.
 *
 * Instead of asking the LLM for products (its web index barely covers
 * marketplace catalogues), the model is used for what it is genuinely good
 * at: interpreting a natural-language request ("компьютер до 100к") and
 * translating it into ready-to-open marketplace search URLs with the right
 * query text and price filters baked into the query string.
 *
 * Those URLs are then handed to the existing scrapers (Playwright /
 * Camoufox), which already know how to render each SERP and extract cards —
 * no LLM product mapping, no hallucinated offers.
 *
 * Every returned URL is hard-validated (scheme, host allow-list, SERP path)
 * before it may reach a scraper; anything suspicious is dropped and the
 * affected provider silently falls back to its plain-text search. Any
 * failure returns an empty map — the feature must never break a search.
 */
class PerplexitySearchUrlService
{
    public const META_KEY = 'ai_urls';

    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты — модуль построения поисковых ссылок агрегатора маркетплейсов.
Пользователь вводит свободный запрос, иногда с бюджетом или уточнениями
(например: «компьютер до 100к», «кроссовки nike до 8000 рублей»).

Твоя задача — для каждого магазина построить ОДНУ готовую к открытию ссылку
на страницу ПОИСКА или категории этого магазина, в которую уже вшиты
текстовый запрос и ценовые фильтры, выведенные из запроса пользователя.

Правила построения ссылок:
- Из бюджета («до 100к», «дешевле 5000») выводи целое число рублей и вшивай
  его как фильтр максимальной цены; «от N» — как фильтр минимальной цены.
  «100к» = 100000 рублей. Если бюджета нет — ценовые параметры не добавляй.
- В текстовый параметр подставляй очищенный запрос на русском языке:
  без слов-ограничителей бюджета («до», «дешевле», «дороже») и без кавычек.
- Ссылка должна открываться в браузере и сразу показывать выдачу товаров
  с применёнными фильтрами. Никаких карточек отдельных товаров, главных
  страниц и редакторских подборок — только поисковая выдача или страница
  категории с фильтром цены.
- Все параметры — в query-строке, значения корректно закодированы.
- Если по магазину ссылку построить нельзя — верни по нему пустую строку.

Форматы ссылок (используй именно их):
- Ozon: https://www.ozon.ru/search/?text=<запрос>&price_to=<целое число рублей>
  (минимальная цена — параметром price_from).
  Если для запроса существует готовая категорийная страница с ценовым
  диапазоном (например: https://www.ozon.ru/category/sistemnye-bloki-do-100000-rubley/),
  допустимо вернуть её — она точнее обычного поиска.
- Яндекс Маркет: https://market.yandex.ru/search?text=<запрос>&price_to=<целое число рублей>
  (минимальная цена — параметром price_from)
- Wildberries: https://www.wildberries.ru/catalog/0/search.aspx?search=<запрос>&price_max=<целое число рублей>
  (минимальная цена — параметром price_min)
PROMPT;

    /**
     * Hard allow-list of hosts a generated URL may ever point at. The scraper
     * services enforce the same list again — defence in depth against both a
     * hallucinating model and any tampered cache payload.
     */
    private const URL_RULES = [
        'ozon' => [
            'field' => 'ozon_search_url',
            'hosts' => ['www.ozon.ru', 'ozon.ru'],
            // Category landing pages with a price range baked into the slug
            // (…/category/sistemnye-bloki-do-100000-rubley/) scrape just as
            // well as the SERP and often carry a tighter assortment.
            'paths' => ['/search/', '/category/'],
        ],
        'yandex_market' => [
            'field' => 'yandex_market_search_url',
            'hosts' => ['market.yandex.ru'],
            'paths' => ['/search'],
        ],
        'wildberries' => [
            'field' => 'wildberries_search_url',
            'hosts' => ['www.wildberries.ru', 'wildberries.ru', 'www.wb.ru', 'wb.ru'],
            'paths' => ['/catalog/'],
        ],
    ];

    public function __construct(private readonly PerplexityClient $client) {}

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    /**
     * Resolve marketplace search URLs for a free-text user query.
     *
     * @return array<string, string> provider code => validated search URL
     */
    public function urlsFor(string $text): array
    {
        $text = trim($text);

        if (! $this->isEnabled() || mb_strlen($text) < 2) {
            return [];
        }

        $cacheKey = $this->cacheKey($text);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->client->completeStructured(
            self::SYSTEM_PROMPT,
            sprintf('Запрос пользователя: «%s»', $text),
            $this->jsonSchema(),
        );

        $urls = [];

        foreach (self::URL_RULES as $code => $rule) {
            $validated = $this->validateUrl($payload[$rule['field']] ?? null, $rule['hosts'], $rule['paths']);

            if ($validated !== null) {
                $urls[$code] = $validated;
            } else {
                Log::info('AI search URL rejected by validation.', [
                    'provider' => $code,
                    'raw' => mb_substr((string) ($payload[$rule['field']] ?? ''), 0, 300),
                ]);
            }
        }

        try {
            Cache::put(
                $cacheKey,
                $urls,
                (int) config('marketplace.search.cache_ttl_seconds', 900)
            );
        } catch (Throwable) {
            // A cache miss only costs one extra LLM call next time.
        }

        return $urls;
    }

    public function cacheKey(string $text): string
    {
        return config('marketplace.search.cache_prefix', 'product-search')
            . ':ai-search-urls:v1:' . hash('sha256', mb_strtolower(trim($text)));
    }

    /**
     * Strict-mode schema: all three fields are required strings, so the API
     * always returns the full shape and an empty string marks "not possible".
     *
     * @return array<string, mixed>
     */
    private function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ozon_search_url' => ['type' => 'string'],
                'yandex_market_search_url' => ['type' => 'string'],
                'wildberries_search_url' => ['type' => 'string'],
            ],
            'required' => ['ozon_search_url', 'yandex_market_search_url', 'wildberries_search_url'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Only an https URL on an allow-listed host whose path starts with one of
     * the store's SERP/category prefixes may ever reach a scraper; everything
     * else yields null and the provider falls back to its plain-text search.
     *
     * @param string[] $hosts
     * @param string[] $pathPrefixes
     */
    private function validateUrl(mixed $url, array $hosts, array $pathPrefixes): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        // FILTER_VALIDATE_URL is not used on purpose: it rejects perfectly
        // openable links whose query values hold raw (unescaped) Cyrillic.
        // The scheme/host/path allow-list below is the real guard, and the
        // scraper services re-validate the same rules before navigating.
        $parts = parse_url($url);

        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || ! in_array(mb_strtolower($parts['host']), $hosts, true)
        ) {
            return null;
        }

        foreach ($pathPrefixes as $prefix) {
            if (str_starts_with($parts['path'] ?? '/', $prefix)) {
                return $url;
            }
        }

        return null;
    }
}
