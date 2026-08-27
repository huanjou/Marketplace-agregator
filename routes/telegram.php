<?php

use SergiX44\Nutgram\Nutgram;
use App\Jobs\ProcessTelegramSearch;

/** @var Nutgram $bot */

$bot->onCommand('start', function (Nutgram $bot) {
    $bot->sendMessage('Привет! Отправь мне название товара, и я найду топ-3 самых дешевых варианта со всех маркетплейсов.');
})->description('Начать работу');

$bot->onText('^([^/].*)', function (Nutgram $bot, string $text) {
    $message = $bot->sendMessage('🔍 Ищу самые дешевые варианты...');
    
    if ($message) {
        ProcessTelegramSearch::dispatch(
            $bot->chatId(),
            $message->message_id,
            $text
        );
    }
});
