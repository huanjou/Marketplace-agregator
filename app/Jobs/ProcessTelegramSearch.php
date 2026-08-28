<?php

namespace App\Jobs;

use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Enums\SearchSortField;
use App\Services\ProductSearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessTelegramSearch implements ShouldQueue
{
    use Queueable;

    public $timeout = 120; // 2 minutes

    public function __construct(
        public int $chatId,
        public int $messageId,
        public string $text,
        public int $page = 1,
        public string $sort = 'price_asc'
    ) {}

    public function handle(ProductSearchService $searchService, Nutgram $bot): void
    {
        try {
            // Save search text to cache for pagination/sorting callbacks
            Cache::put("tg_search_{$this->chatId}", $this->text, now()->addHours(24));

            $sortField = SearchSortField::tryFrom($this->sort) ?? SearchSortField::PriceAsc;
            $sortDirection = match ($sortField) {
                SearchSortField::RatingDesc => 'desc',
                SearchSortField::PriceDesc => 'desc',
                default => 'asc',
            };

            $providerCodes = Cache::get("tg_providers_{$this->chatId}", []);

            $query = new ProductSearchQuery(
                text: $this->text,
                sort: new ProductSort($sortField, $sortDirection),
                page: $this->page,
                perPage: 3,
                providerCodes: $providerCodes
            );

            $result = $searchService->search($query);

            if (empty($result->items)) {
                $bot->editMessageText(
                    text: 'К сожалению, по вашему запросу ничего не найдено 😔',
                    chat_id: $this->chatId,
                    message_id: $this->messageId
                );
                return;
            }

            $response = "🏷 <b>Результаты поиска (Страница {$this->page}):</b>\n";
            $response .= "<i>Запрос: " . htmlspecialchars(mb_substr($this->text, 0, 50)) . "</i>\n\n";
            $keyboard = InlineKeyboardMarkup::make();

            foreach ($result->items as $index => $item) {
                $num = ($this->page - 1) * 3 + $index + 1;
                $price = $item->priceFormatted();
                $rating = $item->ratingValue ? "⭐ {$item->ratingValue}" : "⭐ Нет";
                $title = mb_strlen($item->title) > 50 ? mb_substr($item->title, 0, 47) . '...' : $item->title;
                $url = $item->productUrl;
                $provider = match ($item->providerCode) {
                    'ozon' => 'Ozon 🔵',
                    'wildberries' => 'WB 🟣',
                    'yandex_market' => 'Я.Маркет 🟡',
                    default => $item->providerCode,
                };

                $titleHtml = htmlspecialchars($title);
                
                $response .= "<b>{$num}. {$titleHtml}</b>\n";
                $response .= "💰 {$price} | {$rating} | {$provider}\n\n";

                // Add button for the product
                $keyboard->addRow(
                    InlineKeyboardButton::make("{$num}. Купить ({$provider})", url: $url)
                );
            }

            // Pagination and sorting buttons
            $navRow = [];
            
            if ($this->page > 1) {
                $prevPage = $this->page - 1;
                $navRow[] = InlineKeyboardButton::make('⬅️ Назад', callback_data: "page:{$prevPage}:{$this->sort}");
            }
            
            // Assume there might be more if we got exactly 3 items
            if (count($result->items) === 3) {
                $nextPage = $this->page + 1;
                $navRow[] = InlineKeyboardButton::make('Вперед ➡️', callback_data: "page:{$nextPage}:{$this->sort}");
            }

            if (!empty($navRow)) {
                $keyboard->addRow(...$navRow);
            }
            
            // Sorting buttons
            $sortPriceBtn = InlineKeyboardButton::make(
                $this->sort === 'price_asc' ? '✅ По цене' : 'По цене', 
                callback_data: "sort:1:price_asc"
            );
            $sortRatingBtn = InlineKeyboardButton::make(
                $this->sort === 'rating_desc' ? '✅ По рейтингу' : 'По рейтингу', 
                callback_data: "sort:1:rating_desc"
            );
            $keyboard->addRow($sortPriceBtn, $sortRatingBtn);

            $bot->editMessageText(
                text: $response,
                chat_id: $this->chatId,
                message_id: $this->messageId,
                parse_mode: 'HTML',
                reply_markup: $keyboard,
                link_preview_options: \SergiX44\Nutgram\Telegram\Types\Message\LinkPreviewOptions::make(is_disabled: true)
            );
        } catch (Throwable $e) {
            Log::error('Telegram search failed', ['exception' => $e]);
            $bot->editMessageText(
                text: 'Произошла ошибка при поиске 😔. Попробуйте еще раз позже.',
                chat_id: $this->chatId,
                message_id: $this->messageId
            );
        }
    }
}
