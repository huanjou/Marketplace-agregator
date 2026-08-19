<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Поиск товаров по маркетплейсам' }}</title>
    @livewireStyles
    <style>
        :root {
            --bg: #f6f6f4;
            --card: #ffffff;
            --ink: #1b1b18;
            --muted: #706f6c;
            --line: #e3e3e0;
            --accent: #f8b803;
            --danger: #d33;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
        }
        a { color: inherit; }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px;
            background: var(--card);
            border-bottom: 1px solid var(--line);
        }
        .brand { font-weight: 700; letter-spacing: .3px; }
        .admin-link { font-size: 14px; color: var(--muted); text-decoration: none; }
        .admin-link:hover { color: var(--ink); }
        main { max-width: 1120px; margin: 0 auto; padding: 32px 24px 64px; }
        h1 { font-size: 28px; margin: 0 0 18px; }
        .search-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        .search-row { display: flex; gap: 10px; }
        .search-row input[type="search"] {
            flex: 1;
            font-size: 16px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            outline: none;
        }
        .search-row input[type="search"]:focus { border-color: var(--accent); }
        button[type="submit"] {
            font-size: 16px;
            padding: 12px 26px;
            border: none;
            border-radius: 10px;
            background: var(--ink);
            color: #fff;
            cursor: pointer;
        }
        button[type="submit"]:hover { background: #000; }
        button[type="submit"]:disabled { opacity: .6; cursor: wait; }
        .providers { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; }
        .providers label { display: flex; align-items: center; gap: 6px; font-size: 14px; color: var(--muted); cursor: pointer; }
        .notice { margin-top: 12px; font-size: 14px; color: var(--danger); }
        .partial { margin-top: 12px; font-size: 13px; color: var(--muted); }
        .meta { margin: 18px 0 10px; font-size: 13px; color: var(--muted); }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: box-shadow .15s;
        }
        .card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); }
        .thumb {
            aspect-ratio: 1;
            background: #fafafa center / contain no-repeat;
            border-bottom: 1px solid var(--line);
        }
        .card-body { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
        .title {
            font-size: 13.5px;
            line-height: 1.35;
            text-decoration: none;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .title:hover { color: #8a6d00; }
        .price-row { display: flex; align-items: baseline; gap: 8px; margin-top: auto; }
        .price { font-weight: 700; font-size: 16px; }
        .old-price { font-size: 12.5px; color: var(--muted); text-decoration: line-through; }
        .foot { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); }
        .badge {
            padding: 2px 8px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fafafa;
        }
        /* Marketplace highlights: Яндекс — жёлтый, WB — фиолетовый, Ozon — голубой. */
        .badge--yandex_market { background: #fef6c2; border-color: #f5d442; color: #8a6d00; }
        .badge--wildberries   { background: #f3e4fd; border-color: #c78ae8; color: #7b2ea8; }
        .badge--ozon          { background: #dff1fd; border-color: #7ec8ef; color: #0a6ea8; }
        .dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            display: inline-block;
            background: var(--line);
        }
        .dot--yandex_market { background: #f5d442; }
        .dot--wildberries   { background: #b266d9; }
        .dot--ozon          { background: #59b7ec; }
        .rating { color: #b7791f; }
        .empty { padding: 40px 0; text-align: center; color: var(--muted); }
        .pagination { display: flex; gap: 6px; justify-content: center; margin-top: 26px; flex-wrap: wrap; }
        .pagination button {
            min-width: 38px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            background: var(--card);
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
        }
        .pagination button.current { background: var(--ink); color: #fff; border-color: var(--ink); }
        .spinner {
            display: inline-block;
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: -2px;
            margin-right: 6px;
        }
        @@keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">Агрегатор маркетплейсов</div>
        <a class="admin-link" href="{{ url('/admin') }}">Вход для администратора</a>
    </div>

    <main>
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
