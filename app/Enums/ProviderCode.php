<?php

declare(strict_types=1);

namespace App\Enums;

enum ProviderCode: string
{
    case Fake = 'fake';
    case Ozon = 'ozon';
    case YandexMarket = 'yandex_market';
    case Wildberries = 'wildberries';
}
