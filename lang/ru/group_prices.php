<?php

declare(strict_types=1);

return [
    // Under the price of a card in a list (GP-09).
    'tier_caption' => 'от :qty шт — :price',

    // Product page: quantity tiers of the viewer's group (GP-10).
    'table' => [
        'title' => 'Цены при покупке оптом',
        'qty' => 'Количество',
        'price' => 'Цена за :unit',
        'saving' => 'Экономия',
        'from' => ':qty+ :unit',
        'saving_percent' => '−:percent %',
        'unit_price' => 'Цена за :unit',
        'line_total' => 'Итого',
    ],

    'retail_price' => 'Розничная цена',

    // Cart (GP-11): «Ещё N шт — и цена станет X ₽ за шт».
    'cart' => [
        'tier_hint' => 'Ещё :missing :unit — и цена станет :price за :unit.',
        'tier_applied' => 'Цена за :unit от :qty :unit',
    ],

    // Wholesale price list (CG-17).
    'price_list' => [
        'title' => 'Оптовый прайс-лист',
        'sheet' => 'Прайс-лист',
        'download' => 'Скачать прайс-лист (Excel)',
        'not_ready' => 'Прайс-лист ещё не сформирован. Он обновляется каждую ночь.',
        'built_at' => 'Обновлён :date',
        'columns' => [
            'sku' => 'Артикул',
            'barcode' => 'Штрихкод',
            'name' => 'Название',
            'category' => 'Категория',
            'brand' => 'Бренд',
            'unit' => 'Ед. изм.',
            'stock' => 'Остаток',
            'price' => 'Цена',
            'tiers' => 'Цены от количества',
        ],
        'tier' => 'от :qty: :price',
        'command' => [
            'queued' => 'Поставлено в очередь прайс-листов: :count.',
            'none' => 'Нет групп с включённым разделом «Оптовый прайс-лист».',
            'done_sync' => 'Прайс-листов сформировано: :count.',
        ],
    ],
];
