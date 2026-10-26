<?php

declare(strict_types=1);

return [
    'actions' => [
        'markup_percent' => 'Наценка, %',
        'markup_fixed' => 'Наценка, ₽',
        'cost_markup_percent' => 'Наценка от закупочной цены, %',
        'discount_percent' => 'Скидка, %',
        'discount_fixed' => 'Скидка, ₽',
    ],

    'scopes' => [
        'all' => 'Все товары',
        'categories' => 'Категории',
        'products' => 'Товары',
        'brands' => 'Бренды',
        'flags' => 'Метки',
    ],

    'target_types' => [
        'category' => 'Категория',
        'product' => 'Товар',
        'brand' => 'Бренд',
        'flag' => 'Метка',
    ],

    'flags' => [
        'featured' => 'Хит',
        'new' => 'Новинка',
    ],

    'sources' => [
        'manual' => 'Вручную',
        'import' => 'Импорт',
        'bulk' => 'Массовое действие',
    ],

    'rounding_directions' => [
        'up' => 'вверх',
        'nearest' => 'к ближайшему',
        'down' => 'вниз',
    ],

    // Price explanation lines (PR-03). :price is a formatted amount, :label is "name +20 %".
    'explanation' => [
        'base' => 'База :price',
        'markup' => 'Наценка “:label” → :price',
        'discount' => 'Акция “:label” → :price',
        'skipped' => 'Пропущено “:name”: :reason',
        'skipped_reasons' => [
            'no_cost_price' => 'нет закупочной цены',
        ],
        'rounding' => 'Округление до :step :direction → :price',
        'rounding_psychological' => 'Округление до :step :direction, психологическая цена −1 ₽ → :price',
        'fixed' => 'Фиксированная цена :price, правила не применяются',
        'floor' => 'Цена не ниже нуля → :price',
    ],

    // shop:recalc-prices output and admin banner (PR-02, PR-05).
    'recalc_command' => [
        'choose_one' => 'Укажите ровно одну опцию: --all или --boundaries.',
        'queued' => 'Поставлено в очередь товаров: :products, задач: :jobs.',
        'done_sync' => 'Пересчитано товаров: :products.',
    ],
    'recalc_banner' => 'Пересчёт цен: :done из :total',
];
