<?php

declare(strict_types=1);

return [

    // Events of TN-02: the label in «Настройки → Telegram» and in the order card.
    'events' => [
        'order_created' => 'Обращение создано',
        'status_changed' => 'Статус изменён',
        'reservation_expiring' => 'Резерв истекает через 6 часов',
        'reservation_expired' => 'Резерв истёк или снят',
        'items_changed' => 'Состав заказа изменён сотрудником',
        'wholesale_decision' => 'Решение по заявке на опт',
        'export_ready' => 'Выгрузка истории готова',
    ],

    // «Настройки → Telegram → Уведомления покупателю» (TN-02).
    'admin' => [
        'section' => 'Уведомления покупателю',
        'section_help' => 'Какие события отправлять покупателям в Telegram. Покупатель может отключить любое из них у себя в кабинете или командой /stop в боте.',
        'export_ready_help' => 'Выгрузку покупатель заказывает сам и видит в кабинете, поэтому по умолчанию отключено.',
    ],

    // Cabinet «Уведомления» → Telegram (TN-05).
    'cabinet' => [
        'events_title' => 'Присылать в Telegram',
        'save' => 'Сохранить',
        'saved' => 'Настройки Telegram сохранены.',
        'stopped' => 'Уведомления отключены командой /stop или кнопкой в боте. Чтобы получать их снова, нажмите /start в боте.',
        'events' => [
            'order_created' => 'Обращение принято',
            'status_changed' => 'Смена статуса',
            'reservation_expiring' => 'Резерв скоро истекает',
            'reservation_expired' => 'Резерв истёк или снят',
            'items_changed' => 'Изменение состава заказа',
            'wholesale_decision' => 'Решение по заявке на опт',
            'export_ready' => 'Выгрузка истории готова',
        ],
    ],

    // Message texts (TN-03). Placeholders are escaped by the builder.
    'type' => 'Тип',
    'items' => 'Состав',
    'more_items' => '…и ещё :count поз.',
    'total' => 'Сумма',
    'delivery' => 'Получение',
    'address' => 'Адрес',
    'reserved_until' => 'Резерв до',
    'price_on_request' => 'цена по запросу',

    'created' => [
        'title' => 'Обращение № :number принято',
    ],
    'status' => [
        'title' => ':type № :number',
        'status' => 'Статус',
    ],
    'expired' => [
        'title' => 'Резерв № :number истёк',
        'text' => 'Срок резерва закончился, товар снова в продаже. Оформите резерв заново, если он нужен.',
    ],
    'released' => [
        'title' => 'Резерв № :number снят',
        'text' => 'Резерв отменён, товар снова в продаже.',
    ],
    'expiring' => [
        'title' => 'Резерв № :number скоро истечёт',
        'text' => 'Заберите товар до конца срока или свяжитесь с магазином, чтобы продлить резерв.',
        'phone' => 'Телефон магазина: :phone',
    ],
    'items_changed' => [
        'title' => 'Состав обращения № :number изменён',
        'total' => 'Сумма: было :old, стало :new',
    ],
    'wholesale' => [
        'approved_title' => 'Заявка на оптовые цены одобрена',
        'approved_text' => 'Вам доступна группа «:group».',
        'rejected_title' => 'Заявка на оптовые цены отклонена',
        'reason' => 'Причина',
    ],
    'export' => [
        'title' => 'Выгрузка истории заказов готова',
        'text' => 'Файл (:format) можно скачать в кабинете до :until.',
    ],

    'button' => [
        'open_order' => 'Открыть заказ',
        'open_shop' => 'Открыть магазин',
        'call' => 'Позвонить в магазин',
        'stop' => 'Не присылать уведомления',
    ],

    // Order card, block «Сообщения в Telegram» (TN-07).
    'order' => [
        'section' => 'Сообщения в Telegram',
        'empty' => 'Покупателю ничего не отправлялось.',
        'when' => 'Когда',
        'event' => 'Событие',
        'status' => 'Статус',
        'detail' => 'Подробности',
        'reasons' => [
            'chat_not_started' => 'Покупатель не запускал бота',
            'bot_blocked' => 'Покупатель заблокировал бота',
            'notifications_stopped' => 'Покупатель отключил уведомления',
            'disabled_by_customer' => 'Событие отключено в кабинете покупателя',
            'recipient_unavailable' => 'Получатель стал недоступен до отправки',
            'queue_error' => 'Не удалось поставить в очередь',
        ],
    ],

];
