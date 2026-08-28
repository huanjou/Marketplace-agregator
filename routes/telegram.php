<?php

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use App\Jobs\ProcessTelegramSearch;
use Illuminate\Support\Facades\Cache;

/** @var Nutgram $bot */

$mainMenu = ReplyKeyboardMarkup::make(resize_keyboard: true)
    ->addRow(
        KeyboardButton::make('🔍 Найти товар'),
        KeyboardButton::make('⚙️ Настройки')
    )->addRow(
        KeyboardButton::make('ℹ️ Справка')
    );

$bot->onCommand('start', function (Nutgram $bot) use ($mainMenu) {
    $bot->sendMessage(
        text: 'Привет! Я бот для поиска самых выгодных цен на маркетплейсах. Выберите действие в меню ниже или просто отправьте мне название товара.',
        reply_markup: $mainMenu
    );
})->description('Начать работу');

$bot->onText('🔍 Найти товар', function (Nutgram $bot) use ($mainMenu) {
    $bot->sendMessage(
        text: 'Пожалуйста, отправьте мне название товара, который вы хотите найти.',
        reply_markup: $mainMenu
    );
});

$bot->onText('ℹ️ Справка', function (Nutgram $bot) use ($mainMenu) {
    $bot->sendMessage(
        text: "Я ищу товары на Ozon, Wildberries и Яндекс Маркете и выдаю самые выгодные варианты.\n\nПросто отправьте мне текст с названием товара!",
        reply_markup: $mainMenu
    );
});

$bot->onText('⚙️ Настройки', function (Nutgram $bot) {
    $chatId = $bot->chatId();
    $selected = Cache::get("tg_providers_{$chatId}", ['ozon', 'wildberries', 'yandex_market']);
    
    $providers = [
        'ozon' => 'Ozon',
        'wildberries' => 'Wildberries',
        'yandex_market' => 'Яндекс Маркет',
    ];
    
    $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
    foreach ($providers as $code => $name) {
        $icon = in_array($code, $selected) ? '✅' : '❌';
        $keyboard->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("{$icon} {$name}", callback_data: "toggle_provider:{$code}")
        );
    }
    
    $bot->sendMessage(
        text: 'Выберите маркетплейсы для поиска:',
        reply_markup: $keyboard
    );
});

// Обработчик для любых текстовых сообщений, которые не являются командами или кнопками меню
$bot->onText('^(?!/(?:start|help)|🔍 Найти товар|⚙️ Настройки|ℹ️ Справка)(.+)', function (Nutgram $bot, string $text) {
    $message = $bot->sendMessage('🔍 Ищу самые дешевые варианты...');
    
    if ($message) {
        ProcessTelegramSearch::dispatch(
            $bot->chatId(),
            $message->message_id,
            trim($text)
        );
    }
});

// Обработчики кнопок пагинации и сортировки
$bot->onCallbackQueryData('page:{page}:{sort}', function (Nutgram $bot, $page, $sort) {
    $chatId = $bot->chatId();
    $text = Cache::get("tg_search_{$chatId}");
    
    if (!$text) {
        $bot->answerCallbackQuery(text: 'Время сессии истекло. Пожалуйста, повторите поиск.', show_alert: true);
        return;
    }
    
    $bot->answerCallbackQuery();
    
    ProcessTelegramSearch::dispatch(
        $chatId,
        $bot->message()->message_id,
        $text,
        (int) $page,
        $sort
    );
});

$bot->onCallbackQueryData('sort:{page}:{sort}', function (Nutgram $bot, $page, $sort) {
    $chatId = $bot->chatId();
    $text = Cache::get("tg_search_{$chatId}");
    
    if (!$text) {
        $bot->answerCallbackQuery(text: 'Время сессии истекло. Пожалуйста, повторите поиск.', show_alert: true);
        return;
    }
    
    $bot->answerCallbackQuery(text: 'Сортирую результаты...');
    
    ProcessTelegramSearch::dispatch(
        $chatId,
        $bot->message()->message_id,
        $text,
        (int) $page,
        $sort
    );
});

$bot->onCallbackQueryData('toggle_provider:{code}', function (Nutgram $bot, string $code) {
    $chatId = $bot->chatId();
    $selected = Cache::get("tg_providers_{$chatId}", ['ozon', 'wildberries', 'yandex_market']);
    
    if (in_array($code, $selected)) {
        $selected = array_diff($selected, [$code]);
    } else {
        $selected[] = $code;
    }
    
    // Не даем отключить все
    if (empty($selected)) {
        $bot->answerCallbackQuery(text: 'Должен быть выбран хотя бы один маркетплейс!', show_alert: true);
        return;
    }
    
    Cache::put("tg_providers_{$chatId}", $selected, now()->addDays(30));
    
    $providers = [
        'ozon' => 'Ozon',
        'wildberries' => 'Wildberries',
        'yandex_market' => 'Яндекс Маркет',
    ];
    
    $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
    foreach ($providers as $c => $name) {
        $icon = in_array($c, $selected) ? '✅' : '❌';
        $keyboard->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("{$icon} {$name}", callback_data: "toggle_provider:{$c}")
        );
    }
    
    $bot->editMessageReplyMarkup(reply_markup: $keyboard);
    $bot->answerCallbackQuery();
});
