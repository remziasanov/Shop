<?php

declare(strict_types=1);

return [
    'types' => [
        'percent' => 'Процент',
        'fixed' => 'Фиксированная сумма',
        'free_delivery' => 'Бесплатная доставка',
    ],

    'scopes' => [
        'all' => 'Все товары',
        'categories' => 'Категории',
        'products' => 'Товары',
        'brands' => 'Бренды',
    ],

    'link_targets' => [
        'product' => 'Товар',
        'variant' => 'Модификация товара',
        'category' => 'Категория',
        'catalog_filter' => 'Каталог с фильтрами',
        'url' => 'Страница магазина',
    ],

    'batch_statuses' => [
        'queued' => 'В очереди',
        'running' => 'Генерируется',
        'done' => 'Готова',
        'failed' => 'Ошибка',
    ],

    // PM-09: the message of every refusal; an unknown or inactive code reveals nothing else.
    'messages' => [
        'not_found' => 'Промокод не найден',
        'not_started' => 'Промокод действует с :date',
        'expired' => 'Срок действия промокода истёк',
        'group_not_allowed' => 'Промокод недоступен для вашей группы покупателей',
        'only_registered' => 'Промокод только для зарегистрированных покупателей',
        'exhausted' => 'Промокод исчерпан',
        'per_customer_limit' => 'Вы уже использовали этот промокод',
        'min_order' => 'Минимальная сумма заказа для кода — :min',
        'no_eligible_items' => 'Промокод не подходит к товарам в корзине',
        'no_discount' => 'Промокод не даёт дополнительной скидки на товары в корзине',
        'throttled' => 'Слишком много попыток. Повторите через :seconds с.',
        'empty' => 'Введите промокод',
        'replaced' => 'Промокод :old заменён на :new',
    ],

    // PM-13, PM-23: the cart and checkout box and the totals.
    'box' => [
        'toggle' => 'Есть промокод?',
        'label' => 'Промокод',
        'placeholder' => 'Введите промокод',
        'apply' => 'Применить',
        'remove' => 'Убрать',
        'remove_label' => 'Убрать промокод :code',
        'applied' => 'Промокод :code применён',
        'not_applied' => 'Промокод :code сейчас не действует: :reason',
        'saved' => 'Промокод :code сохранён: :reason',
        'discount' => 'Скидка по промокоду :code',
        'line_discount' => 'Промокод −:amount',
        'free_delivery' => 'Бесплатная доставка',
        'total' => 'Итого',
    ],

    // PM-13, PM-18: the promo and the manual discount lines of an order (page, e-mail, print, Telegram).
    'order' => [
        'promo' => 'Промокод :code',
        'promo_free_delivery' => 'бесплатная доставка',
        'manual_discount' => 'Скидка',
    ],

    // PM-22: the notice after /go/{slug} could not lead anywhere.
    'link_notice' => 'Ссылка устарела',

    'errors' => [
        'has_redemptions' => 'Промокод с применениями нельзя удалить — только деактивировать.',
        'count_range' => 'Количество кодов — от 1 до :max.',
        'length_range' => 'Длина случайной части — от :min до :max знаков.',
        'prefix_invalid' => 'Префикс может содержать только латинские буквы, цифры и дефис.',
        'batch_not_done' => 'Партия ещё не сформирована.',
    ],

    'history' => [
        'released' => 'Промокод :code возвращён',
    ],

    'export' => [
        'sheet' => 'Коды партии',
        'columns' => [
            'code' => 'Код',
            'status' => 'Статус',
            'used' => 'Использован',
            'used_at' => 'Дата использования',
            'order' => 'Номер заказа',
        ],
        'status_unused' => 'Не использован',
        'status_used' => 'Использован',
        'status_released' => 'Возвращён',
        'status_inactive' => 'Отключён',
        'yes' => 'Да',
        'no' => 'Нет',
    ],

    'link' => [
        'stale' => 'Ссылка устарела',
        'reasons' => [
            'inactive' => 'Ссылка отключена',
            'not_started' => 'Срок действия ссылки ещё не наступил',
            'expired' => 'Срок действия ссылки истёк',
            'target_unavailable' => 'Назначение ссылки недоступно',
            'target_invalid' => 'Назначение ссылки задано неверно',
        ],
    ],
];
