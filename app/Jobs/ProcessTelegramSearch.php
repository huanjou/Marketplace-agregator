<?php

namespace App\Jobs;

use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSort;
use App\Enums\SearchSortField;
use App\Services\ProductSearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTelegramSearch implements ShouldQueue
{
    use Queueable;

    public $timeout = 120; // 2 minutes

    public function __construct(
        public int $chatId,
        public int $messageId,
        public string $text
    ) {}

    public function handle(ProductSearchService $searchService, Nutgram $bot): void
    {
        try {
            $query = new ProductSearchQuery(
                text: $this->text,
                sort: new ProductSort(SearchSortField::PriceAsc, 'asc'),
                page: 1,
                perPage: 3
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

            $response = "🏷 <b>Топ-3 самых дешевых вариантов:</b>\n\n";

            foreach ($result->items as $index => $item) {
                $num = $index + 1;
                $price = $item->priceFormatted();
                $rating = $item->ratingValue ? "⭐ {$item->ratingValue}" : "⭐ Нет оценок";
                $title = mb_strlen($item->title) > 50 ? mb_substr($item->title, 0, 47) . '...' : $item->title;
                $url = $item->productUrl;
                $provider = match ($item->providerCode) {
                    'ozon' => 'Ozon 🔵',
                    'wildberries' => 'Wildberries 🟣',
                    'yandex_market' => 'Яндекс Маркет 🟡',
                    default => $item->providerCode,
                };

                $titleHtml = htmlspecialchars($title);
                
                $response .= "<b>{$num}. {$titleHtml}</b>\n";
                $response .= "💰 {$price} | {$rating} | {$provider}\n";
                $response .= "<a href=\"{$url}\">Перейти к товару</a>\n\n";
            }

            $bot->editMessageText(
                text: $response,
                chat_id: $this->chatId,
                message_id: $this->messageId,
                parse_mode: 'HTML',
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
