<x-filament-panels::page>
    @push('styles')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=IBM+Plex+Mono:wght@400;500;600&display=swap"
            rel="stylesheet"
        >
        <style>
            /* ------------------------------------------------------------------
             | Product search board — an industrial departure-board / terminal
             | slab that stays intentionally dark in both panel colour schemes.
             | Everything is namespaced under .psd- so it can never leak into
             | Filament's own components.
             ------------------------------------------------------------------ */
            .psd {
                --psd-display: 'Bricolage Grotesque', 'Trebuchet MS', ui-sans-serif, sans-serif;
                --psd-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
                --psd-ink: #0d0b09;
                --psd-ink-2: #191410;
                --psd-ink-3: #221b15;
                --psd-rule: rgba(255, 255, 255, 0.07);
                --psd-amber: #f0b429;
                --psd-text: #f5efe4;
                --psd-muted: #9b8f81;
                --psd-green: #5ed394;
                --psd-red: #ff6a5a;

                position: relative;
                overflow: hidden;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 0.75rem;
                background:
                    radial-gradient(130% 90% at 8% -20%, #2a2118 0%, transparent 58%),
                    linear-gradient(180deg, #16120e 0%, var(--psd-ink) 100%);
                box-shadow: 0 32px 64px -34px rgba(0, 0, 0, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.05);
                color: var(--psd-text);
                font-family: var(--psd-mono);
                font-feature-settings: 'tnum' 1;
            }

            /* Film grain + the amber rule that anchors the whole slab. */
            .psd::before {
                content: '';
                position: absolute;
                inset: 0;
                z-index: 0;
                pointer-events: none;
                opacity: 0.045;
                mix-blend-mode: screen;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='3'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)'/%3E%3C/svg%3E");
            }

            .psd::after {
                content: '';
                position: absolute;
                inset: 0 0 auto 0;
                height: 2px;
                background: linear-gradient(90deg, var(--psd-amber) 0%, rgba(240, 180, 41, 0.25) 38%, transparent 72%);
            }

            .psd > * {
                position: relative;
                z-index: 1;
            }

            .psd-label {
                font-size: 0.5625rem;
                font-weight: 500;
                letter-spacing: 0.22em;
                text-transform: uppercase;
                color: var(--psd-muted);
            }

            /* --- ticker ---------------------------------------------------- */
            .psd-ticker {
                display: flex;
                flex-wrap: wrap;
                border-bottom: 1px solid var(--psd-rule);
            }

            .psd-stat {
                flex: 1 1 8rem;
                min-width: 8rem;
                padding: 0.85rem 1.15rem;
                border-right: 1px solid var(--psd-rule);
            }

            .psd-stat__value {
                margin-top: 0.2rem;
                font-size: 1.375rem;
                line-height: 1.1;
                letter-spacing: -0.03em;
                font-variant-numeric: tabular-nums;
            }

            .psd-stat--accent .psd-stat__value {
                color: var(--psd-amber);
            }

            .psd-stat--echo {
                flex: 2 1 16rem;
            }

            .psd-stat--echo .psd-stat__value {
                overflow: hidden;
                font-family: var(--psd-display);
                font-size: 1.5rem;
                white-space: nowrap;
                text-overflow: ellipsis;
                color: var(--psd-amber);
            }

            .psd-caret {
                display: inline-block;
                width: 0.55rem;
                margin-left: 0.15rem;
                border-bottom: 3px solid var(--psd-amber);
                animation: psd-blink 1.1s steps(1) infinite;
            }

            @keyframes psd-blink {
                50% { opacity: 0; }
            }

            .psd-dot {
                display: inline-block;
                width: 0.5rem;
                height: 0.5rem;
                margin-right: 0.4rem;
                border-radius: 50%;
                vertical-align: 0.05em;
            }

            /* --- board caption -------------------------------------------- */
            .psd-caption {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem 1.25rem;
                align-items: center;
                justify-content: space-between;
                padding: 0.7rem 1.15rem;
                border-bottom: 1px solid var(--psd-rule);
                background: rgba(255, 255, 255, 0.015);
            }

            .psd-tags {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
            }

            .psd-tag {
                padding: 0.2rem 0.45rem;
                border: 1px solid var(--psd-rule);
                font-size: 0.5625rem;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: var(--psd-muted);
            }

            .psd-alert {
                display: flex;
                gap: 0.6rem;
                padding: 0.7rem 1.15rem;
                border-bottom: 1px solid var(--psd-rule);
                background: rgba(255, 106, 90, 0.09);
                font-size: 0.6875rem;
                line-height: 1.6;
                color: #ffb7ad;
            }

            /* --- grid: 1px gaps read as hairline board rules -------------- */
            .psd-grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 1px;
                background: var(--psd-rule);
            }

            @media (min-width: 640px) {
                .psd-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .psd-card--feature { grid-column: span 2; }
            }

            @media (min-width: 1024px) {
                .psd-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            }

            @media (min-width: 1440px) {
                .psd-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            }

            /* --- card ------------------------------------------------------ */
            .psd-card {
                display: flex;
                flex-direction: column;
                gap: 0.7rem;
                padding: 0.9rem;
                background: var(--psd-ink-2);
                transition: background 0.3s ease;
                animation: psd-rise 0.55s cubic-bezier(0.2, 0.75, 0.2, 1) both;
                animation-delay: calc(var(--i, 0) * 35ms);
            }

            .psd-card:hover {
                background: var(--psd-ink-3);
            }

            @keyframes psd-rise {
                from { opacity: 0; transform: translateY(14px); }
                to { opacity: 1; transform: none; }
            }

            @media (prefers-reduced-motion: reduce) {
                .psd-card { animation: none; }
            }

            .psd-thumb {
                position: relative;
                overflow: hidden;
                aspect-ratio: 1 / 1;
                border: 1px solid var(--psd-rule);
                background: #0a0908;
            }

            .psd-thumb img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                filter: saturate(0.85) contrast(1.05);
                transition: transform 0.6s cubic-bezier(0.2, 0.7, 0.2, 1), filter 0.6s ease;
            }

            .psd-card:hover .psd-thumb img {
                transform: scale(1.06);
                filter: saturate(1.12) contrast(1.1);
            }

            .psd-thumb--empty {
                display: flex;
                align-items: center;
                justify-content: center;
                background-image: repeating-linear-gradient(
                    45deg,
                    rgba(255, 255, 255, 0.05) 0 1px,
                    transparent 1px 9px
                );
            }

            .psd-thumb__index {
                position: absolute;
                top: 0;
                left: 0;
                padding: 0.18rem 0.45rem;
                background: var(--psd-amber);
                font-size: 0.625rem;
                font-weight: 600;
                letter-spacing: 0.1em;
                color: #16110c;
            }

            .psd-thumb__discount {
                position: absolute;
                right: 0;
                bottom: 0;
                padding: 0.18rem 0.45rem;
                background: #e14b34;
                font-size: 0.625rem;
                font-weight: 600;
                letter-spacing: 0.06em;
                color: #fff;
            }

            .psd-chip {
                display: inline-flex;
                align-items: center;
                align-self: flex-start;
                gap: 0.4rem;
                padding: 0.22rem 0.5rem;
                border: 1px solid;
                border-color: color-mix(in srgb, var(--chip, #a8a29b) 45%, transparent);
                border-left: 3px solid var(--chip, #a8a29b);
                background: rgba(255, 255, 255, 0.03);
                font-size: 0.5625rem;
                font-weight: 500;
                letter-spacing: 0.16em;
                text-transform: uppercase;
                color: var(--chip, #a8a29b);
            }

            .psd-chip--fake { --chip: #a8a29b; }
            .psd-chip--ozon { --chip: #6b93ff; }
            .psd-chip--yandex_market { --chip: #ffcc00; }
            .psd-chip--wildberries { --chip: #cb11ab; }

            .psd-card__title {
                display: -webkit-box;
                overflow: hidden;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
                font-family: var(--psd-display);
                font-size: 1.0625rem;
                font-weight: 600;
                line-height: 1.22;
                letter-spacing: -0.015em;
                color: var(--psd-text);
            }

            .psd-card__brand {
                margin-top: 0.2rem;
                font-size: 0.625rem;
                letter-spacing: 0.16em;
                text-transform: uppercase;
                color: var(--psd-muted);
            }

            .psd-price {
                display: flex;
                flex-wrap: wrap;
                align-items: baseline;
                gap: 0.5rem;
            }

            .psd-price__now {
                font-size: 1.4375rem;
                font-weight: 500;
                letter-spacing: -0.035em;
                font-variant-numeric: tabular-nums;
            }

            .psd-price__old {
                font-size: 0.75rem;
                color: var(--psd-muted);
                text-decoration: line-through;
                text-decoration-color: var(--psd-red);
            }

            .psd-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem 0.9rem;
                align-items: center;
                font-size: 0.625rem;
                letter-spacing: 0.06em;
                color: var(--psd-muted);
            }

            .psd-stars {
                letter-spacing: 0.08em;
                color: rgba(255, 255, 255, 0.16);
            }

            .psd-stars i {
                font-style: normal;
            }

            .psd-stars i.is-on {
                color: var(--psd-amber);
            }

            .psd-stock--in { color: var(--psd-green); }
            .psd-stock--out { color: var(--psd-red); }

            .psd-link {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-top: auto;
                padding: 0.55rem 0.7rem;
                border: 1px solid rgba(240, 180, 41, 0.32);
                font-size: 0.5625rem;
                font-weight: 500;
                letter-spacing: 0.18em;
                text-transform: uppercase;
                color: var(--psd-amber);
                transition: background 0.22s ease, color 0.22s ease, border-color 0.22s ease;
            }

            .psd-link:hover {
                background: var(--psd-amber);
                border-color: var(--psd-amber);
                color: #16110c;
            }

            .psd-link em {
                font-style: normal;
                transition: transform 0.22s ease;
            }

            .psd-link:hover em {
                transform: translateX(4px);
            }

            /* Featured first hit breaks the grid and turns sideways. */
            @media (min-width: 640px) {
                .psd-card--feature {
                    flex-direction: row;
                    gap: 1.1rem;
                    padding: 1.1rem;
                    background: linear-gradient(100deg, var(--psd-ink-3) 0%, var(--psd-ink-2) 55%);
                }

                .psd-card--feature .psd-thumb {
                    flex: 0 0 42%;
                    aspect-ratio: 4 / 3;
                }

                .psd-card--feature .psd-card__body {
                    display: flex;
                    flex: 1 1 auto;
                    flex-direction: column;
                    gap: 0.7rem;
                }

                .psd-card--feature .psd-card__title {
                    font-size: 1.5rem;
                    -webkit-line-clamp: 3;
                }

                .psd-card--feature .psd-price__now {
                    font-size: 2rem;
                }
            }

            /* --- empty state ---------------------------------------------- */
            .psd-empty {
                position: relative;
                overflow: hidden;
                padding: 4.5rem 1.5rem;
                text-align: center;
            }

            .psd-empty__title {
                font-family: var(--psd-display);
                font-size: clamp(2rem, 7vw, 3.75rem);
                font-weight: 700;
                letter-spacing: -0.04em;
                color: rgba(245, 239, 228, 0.16);
            }

            .psd-empty__hint {
                margin-top: 0.75rem;
                font-size: 0.6875rem;
                letter-spacing: 0.16em;
                text-transform: uppercase;
                color: var(--psd-muted);
            }

            .psd-empty::after {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                height: 1px;
                background: linear-gradient(90deg, transparent, var(--psd-amber), transparent);
                opacity: 0.5;
                animation: psd-sweep 3.2s linear infinite;
            }

            @keyframes psd-sweep {
                from { top: 0; }
                to { top: 100%; }
            }

            /* --- pager ---------------------------------------------------- */
            .psd-pager {
                display: flex;
                flex-wrap: wrap;
                gap: 1px;
                border-top: 1px solid var(--psd-rule);
                background: var(--psd-rule);
            }

            .psd-pager__btn {
                padding: 0.7rem 1rem;
                background: var(--psd-ink-2);
                font-family: var(--psd-mono);
                font-size: 0.6875rem;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: var(--psd-muted);
                transition: color 0.2s ease, background 0.2s ease;
            }

            .psd-pager__btn:hover:not(:disabled) {
                background: var(--psd-ink-3);
                color: var(--psd-amber);
            }

            .psd-pager__btn:disabled {
                opacity: 0.32;
                cursor: not-allowed;
            }

            .psd-pager__btn.is-current {
                background: var(--psd-amber);
                color: #16110c;
            }

            .psd-pager__filler {
                flex: 1 1 1rem;
                background: var(--psd-ink-2);
            }

            /* --- scanning overlay ----------------------------------------- */
            .psd-scan {
                display: none;
                position: absolute;
                inset: 0;
                z-index: 5;
                align-items: center;
                justify-content: center;
                background: rgba(10, 9, 8, 0.72);
                backdrop-filter: blur(2px);
                font-size: 0.6875rem;
                letter-spacing: 0.3em;
                text-transform: uppercase;
                color: var(--psd-amber);
            }

            .psd-scan::before {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                height: 2px;
                background: linear-gradient(90deg, transparent, var(--psd-amber), transparent);
                animation: psd-sweep 1.1s linear infinite;
            }
        </style>
    @endpush

    {{-- Query console: native Filament chrome, so it follows the panel theme. --}}
    <x-filament::section>
        <x-slot name="heading">Query console</x-slot>
        <x-slot name="description">
            Fans out to every selected marketplace, merges and de-duplicates the offers.
        </x-slot>

        <form wire:submit.prevent="search">
            {{ $this->form }}

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-filament::button
                    type="submit"
                    size="lg"
                    icon="heroicon-m-magnifying-glass"
                    wire:target="search"
                    wire:loading.attr="disabled"
                >
                    Search
                </x-filament::button>

                <x-filament::button
                    type="button"
                    size="lg"
                    color="gray"
                    icon="heroicon-m-arrow-uturn-left"
                    wire:click="resetSearch"
                >
                    Reset
                </x-filament::button>

                <span class="text-xs text-gray-400 dark:text-gray-500">
                    or press <x-filament::badge class="inline-flex">⌘ + ↵</x-filament::badge>
                </span>
            </div>
        </form>
    </x-filament::section>

    {{-- Result board. --}}
    <div class="psd">
        <div class="psd-scan" wire:loading.delay.flex>Scanning marketplaces…</div>

        <div class="psd-ticker">
            <div class="psd-stat psd-stat--echo">
                <div class="psd-label">Query</div>
                <div class="psd-stat__value">
                    {{ filled($this->query) ? $this->query : 'everything' }}<span class="psd-caret"></span>
                </div>
            </div>

            <div class="psd-stat psd-stat--accent">
                <div class="psd-label">Matches</div>
                <div class="psd-stat__value">{{ number_format((int) ($this->total ?? 0), 0, '.', ' ') }}</div>
            </div>

            <div class="psd-stat">
                <div class="psd-label">Latency</div>
                <div class="psd-stat__value">{{ $this->lastSearchMs !== null ? $this->lastSearchMs . ' ms' : '—' }}</div>
            </div>

            <div class="psd-stat">
                <div class="psd-label">Source</div>
                <div class="psd-stat__value" style="font-size: 0.9375rem; padding-top: 0.35rem;">
                    @if ($this->cacheHit === null)
                        <span class="psd-dot" style="background: #6b6259;"></span>idle
                    @elseif ($this->cacheHit)
                        <span class="psd-dot" style="background: var(--psd-amber);"></span>cache
                    @else
                        <span class="psd-dot" style="background: var(--psd-green);"></span>live
                    @endif
                </div>
            </div>

            <div class="psd-stat">
                <div class="psd-label">Page</div>
                <div class="psd-stat__value">
                    {{ $this->page }}<span style="color: var(--psd-muted); font-size: 0.875rem;">/{{ $this->getLastPage() }}</span>
                </div>
            </div>
        </div>

        <div class="psd-caption">
            <div class="psd-label">Result board</div>

            <div class="psd-tags">
                <span class="psd-tag">sort · {{ str_replace('_', ' ', (string) $this->sort) }}</span>
                <span class="psd-tag">{{ $this->perPage }} / page</span>
                @forelse ($this->providerCodes ?? [] as $code)
                    <span class="psd-tag">{{ $code }}</span>
                @empty
                    <span class="psd-tag">all enabled</span>
                @endforelse
            </div>
        </div>

        @if ($this->errors)
            <div class="psd-alert">
                <span>⚠</span>
                <div>
                    @foreach ($this->errors as $code => $message)
                        <div><strong>{{ $code }}</strong> — {{ $message }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($this->results === null)
            <div class="psd-empty">
                <div class="psd-empty__title">Standing by</div>
                <div class="psd-empty__hint">Run a search to populate the board</div>
            </div>
        @elseif ($this->results === [])
            <div class="psd-empty">
                <div class="psd-empty__title">No matches</div>
                <div class="psd-empty__hint">Loosen the filters or try a broader query</div>
            </div>
        @else
            <div class="psd-grid">
                @foreach ($this->results as $index => $item)
                    @php
                        $isFeature = $this->page === 1 && $index === 0;
                        $rank = (($this->page - 1) * (int) ($this->perPage ?? 20)) + $index + 1;
                        $stars = (int) round($item['rating'] ?? 0);
                    @endphp

                    <article
                        wire:key="hit-{{ $item['fingerprint'] }}-{{ $index }}"
                        class="psd-card @if ($isFeature) psd-card--feature @endif"
                        style="--i: {{ $index }}"
                    >
                        <div class="psd-thumb @if (! $item['imageUrl']) psd-thumb--empty @endif">
                            @if ($item['imageUrl'])
                                <img src="{{ $item['imageUrl'] }}" alt="{{ $item['title'] }}" loading="lazy">
                            @else
                                <span class="psd-label">no image</span>
                            @endif

                            <span class="psd-thumb__index">{{ str_pad((string) $rank, 2, '0', STR_PAD_LEFT) }}</span>

                            @if ($item['discountPercent'])
                                <span class="psd-thumb__discount">−{{ $item['discountPercent'] }}%</span>
                            @endif
                        </div>

                        <div class="psd-card__body">
                            <span class="psd-chip psd-chip--{{ $item['providerCode'] }}">
                                {{ $item['providerName'] }}
                            </span>

                            <div>
                                <h3 class="psd-card__title">{{ $item['title'] }}</h3>
                                @if ($item['brand'])
                                    <div class="psd-card__brand">{{ $item['brand'] }}</div>
                                @endif
                            </div>

                            <div class="psd-price">
                                <span class="psd-price__now">{{ $item['price'] }}</span>
                                @if ($item['oldPrice'])
                                    <span class="psd-price__old">{{ $item['oldPrice'] }}</span>
                                @endif
                            </div>

                            <div class="psd-meta">
                                @if ($item['rating'])
                                    <span class="psd-stars" title="{{ $item['rating'] }}">
                                        @for ($star = 1; $star <= 5; $star++)
                                            <i @class(['is-on' => $star <= $stars])>★</i>
                                        @endfor
                                        <span style="color: var(--psd-text);">{{ number_format((float) $item['rating'], 1) }}</span>
                                        @if ($item['ratingCount'])
                                            <span>({{ number_format((int) $item['ratingCount'], 0, '.', ' ') }})</span>
                                        @endif
                                    </span>
                                @endif

                                @if ($item['availability'] === 'in_stock')
                                    <span class="psd-stock--in">
                                        <span class="psd-dot" style="background: currentColor;"></span>in stock
                                        @if ($item['stockQuantity'] !== null) · {{ $item['stockQuantity'] }} pcs @endif
                                    </span>
                                @elseif ($item['availability'] === 'out_of_stock')
                                    <span class="psd-stock--out">
                                        <span class="psd-dot" style="background: currentColor;"></span>out of stock
                                    </span>
                                @endif

                                @if ($item['category'])
                                    <span>{{ $item['category'] }}</span>
                                @endif
                            </div>

                            @if ($item['productUrl'])
                                <a href="{{ $item['productUrl'] }}" target="_blank" rel="noopener" class="psd-link">
                                    <span>View on marketplace</span>
                                    <em>↗</em>
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($this->getLastPage() > 1)
                <div class="psd-pager">
                    <button
                        type="button"
                        class="psd-pager__btn"
                        wire:click="gotoPage({{ $this->page - 1 }})"
                        @disabled($this->page <= 1)
                    >
                        ← prev
                    </button>

                    @if (! in_array(1, $this->getPaginationWindow(), true))
                        <button type="button" class="psd-pager__btn" wire:click="gotoPage(1)">01</button>
                        <span class="psd-pager__btn" style="cursor: default;">…</span>
                    @endif

                    @foreach ($this->getPaginationWindow() as $pageNumber)
                        <button
                            type="button"
                            @class(['psd-pager__btn', 'is-current' => $pageNumber === $this->page])
                            wire:click="gotoPage({{ $pageNumber }})"
                        >
                            {{ str_pad((string) $pageNumber, 2, '0', STR_PAD_LEFT) }}
                        </button>
                    @endforeach

                    @if (! in_array($this->getLastPage(), $this->getPaginationWindow(), true))
                        <span class="psd-pager__btn" style="cursor: default;">…</span>
                        <button type="button" class="psd-pager__btn" wire:click="gotoPage({{ $this->getLastPage() }})">
                            {{ str_pad((string) $this->getLastPage(), 2, '0', STR_PAD_LEFT) }}
                        </button>
                    @endif

                    <span class="psd-pager__filler"></span>

                    <button
                        type="button"
                        class="psd-pager__btn"
                        wire:click="gotoPage({{ $this->page + 1 }})"
                        @disabled($this->page >= $this->getLastPage())
                    >
                        next →
                    </button>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
