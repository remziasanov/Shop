<?php

declare(strict_types=1);

return [
    'history' => [
        'title' => 'История заказов',
        'customer' => 'Покупатель',
        'period' => 'Период',
        'filters' => 'Отбор',
        'created' => 'Сформировано',
        'number' => 'Номер',
        'date' => 'Дата',
        'type' => 'Тип',
        'status' => 'Статус',
        'total' => 'Сумма',
        'period_total' => 'Итого (без отменённых и истёкших), обращений в таблице: :count',
        'types' => 'типы: :list',
        'statuses' => 'статусы: :list',
        'page' => 'Страница :page из :pages',
    ],

    'items' => [
        'no' => '№',
        'sku' => 'Артикул',
        'name' => 'Наименование',
        'qty' => 'Кол-во',
        'price' => 'Цена',
        'sum' => 'Сумма',
    ],

    'document' => [
        'invoice_title' => 'Счёт на оплату № :number от :date',
        'blank_title' => 'Бланк заказа № :number от :date',
        'bank' => 'Банк получателя',
        'bik' => 'БИК',
        'account' => 'Расчётный счёт',
        'corr_account' => 'Корр. счёт',
        'seller' => 'Продавец',
        'payer' => 'Плательщик',
        'delivery' => 'Получение',
        'payment' => 'Оплата',
        'inn' => 'ИНН',
        'kpp' => 'КПП',
        'ogrn' => 'ОГРН',
        'items_total' => 'Товары',
        'delivery_price' => 'Доставка',
        'total' => 'Итого к оплате',
        'total_in_words' => 'Всего прописью',
        'signature_seller' => 'Продавец (подпись, печать)',
        'signature_customer' => 'Покупатель (подпись)',
        'file' => 'order-:number.pdf',
    ],
];
