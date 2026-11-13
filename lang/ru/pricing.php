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
        'group_discount' => 'Скидка группы :percent % → :price',
    ],

    // Where the price of a customer group comes from (GP-03, GP-12).
    'price_sources' => [
        'tier' => 'Ступень количества',
        'group_price' => 'Точная цена группы',
        'group_discount' => 'Скидка группы',
        'retail' => 'Розничная цена',
        'manual' => 'Цена вручную',
    ],

    // Validation of tiers and exact group prices (GP-14). :price is a formatted amount.
    'tiers' => [
        'errors' => [
            'card_has_variants' => 'У товара с модификациями цены групп задаются для каждой модификации.',
            'invalid_qty' => 'Количество должно быть целым числом от 1.',
            'invalid_price' => 'Ступень от :qty шт: цена должна быть больше нуля.',
            'duplicate_qty' => 'Количество :qty шт указано дважды.',
            'too_many' => 'Не более :max ступеней для товара и группы.',
            'price_grows' => 'Ступень от :qty шт (:price) дороже ступени от :previous_qty шт (:previous_price): при росте количества цена не должна расти.',
        ],
        'warnings' => [
            'above_retail' => 'Ступень от :qty шт (:price) выше розничной цены :retail.',
            'above_group_price' => 'Ступень от :qty шт (:price) выше цены группы без ступени :group_price.',
        ],
    ],

    // Bulk action «Цены группы» (GP-18).
    'group_bulk' => [
        'operations' => [
            'retail_minus_percent' => 'Точная цена = розница −X %',
            'cost_plus_percent' => 'Точная цена = закупка +X %',
            'delete' => 'Удалить цены группы',
        ],
    ],

    // shop:recalc-prices output and admin banner (PR-02, PR-05).
    'recalc_command' => [
        'choose_one' => 'Укажите ровно одну опцию: --all или --boundaries.',
        'queued' => 'Поставлено в очередь товаров: :products, задач: :jobs.',
        'done_sync' => 'Пересчитано товаров: :products.',
    ],
    'recalc_banner' => 'Пересчёт цен: :done из :total',
];
