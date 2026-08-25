<div>
    <h1>Поиск товаров</h1>

    <div class="search-card">
        <form wire:submit="search">
            <div class="search-row">
                <input
                    type="search"
                    wire:model="query"
                    placeholder="Например: наушники, iphone, кроссовки"
                    autofocus
                    autocomplete="off"
                >
                <button type="submit" wire:loading.attr="disabled">
                    <span wire:loading wire:target="search,resolveSearchUrls,runScrape,gotoPage"><span class="spinner"></span></span>Найти
                </button>
            </div>
            <div class="providers">
                @foreach ($this->providerOptions() as $code => $name)
                    <label>
                        <input type="checkbox" wire:model.live="providerCodes" value="{{ $code }}">
                        <span class="dot dot--{{ $code }}"></span>{{ $name }}
                    </label>
                @endforeach
            </div>
        </form>

        @if ($notice)
            <div class="notice">{{ $notice }}</div>
        @endif

        @if ($providerErrors)
            <div class="partial">
                Часть маркетплейсов не ответила:
                {{ implode(' · ', $providerErrors) }}
            </div>
        @endif

        @if ($status)
            <div class="status"><span class="spinner"></span>{{ $status }}</div>
        @endif
    </div>

    @if ($searched)
        <div class="meta">
            <span>Найдено: {{ $total }} · {{ $lastSearchMs }} мс</span>
            @if ($aiUrlsApplied)
                <span class="ai-note">Ссылки с фильтрами подобраны ИИ</span>
            @endif
        </div>

        @if ($results === [])
            <div class="empty">Ничего не найдено. Попробуйте другой запрос.</div>
        @else
            <div class="grid">
                @foreach ($results as $item)
                    <div class="card">
                        <a class="thumb" href="{{ $item['productUrl'] ?? '#' }}" target="_blank" rel="noopener nofollow"
                           @if ($item['imageUrl']) style="background-image:url('{{ $item['imageUrl'] }}')" @endif></a>
                        <div class="card-body">
                            <a class="title" href="{{ $item['productUrl'] ?? '#' }}" target="_blank" rel="noopener nofollow">
                                {{ $item['title'] }}
                            </a>
                            <div class="price-row">
                                <span class="price">{{ $item['price'] }}</span>
                                @if ($item['oldPrice'])
                                    <span class="old-price">{{ $item['oldPrice'] }}</span>
                                @endif
                            </div>
                            <div class="foot">
                                <span class="badge badge--{{ $item['providerCode'] }}">{{ $item['providerName'] }}</span>
                                @if ($item['rating'])
                                    <span class="rating">★ {{ number_format($item['rating'], 1) }}@if ($item['ratingCount']) ({{ $item['ratingCount'] }})@endif</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($this->getLastPage() > 1)
                <div class="pagination">
                    @if ($page > 1)
                        <button wire:click="gotoPage({{ $page - 1 }})">←</button>
                    @endif
                    @foreach ($this->getPaginationWindow() as $p)
                        <button wire:click="gotoPage({{ $p }})" class="{{ $p === $page ? 'current' : '' }}">{{ $p }}</button>
                    @endforeach
                    @if ($page < $this->getLastPage())
                        <button wire:click="gotoPage({{ $page + 1 }})">→</button>
                    @endif
                </div>
            @endif
        @endif
    @endif
</div>
