<?php

declare(strict_types=1);

return [

    'telegram' => [
        'new' => 'Новый запрос: :type :number',
        'expiring' => 'Резерв :number скоро истекает',
        'expired' => 'Резерв истёк автоматически: :number',
        'customer' => 'Клиент',
        'phone' => 'Телефон',
        'items' => 'Позиции',
        'more_items' => '…и ещё :count поз.',
        'total' => 'Сумма',
        'reserved_until' => 'Резерв до',
        'open' => 'Открыть в админке',
        'test' => 'Тестовое сообщение из админки магазина «:shop». Telegram настроен верно.',
    ],

    'mail' => [
        'placed_subject' => ':type :number принят',
        'new_subject' => 'Новый запрос: :type :number',
        'status_subject' => ':type :number: статус «:status»',
        'test_subject' => 'Тестовое письмо',
        'test_body' => 'Это тестовое письмо из админки магазина «:shop». Почта настроена верно.',
        'greeting' => 'Здравствуйте, :name!',
        'items' => 'Состав',
        'item_name' => 'Товар',
        'item_qty' => 'Кол-во',
        'item_total' => 'Сумма',
        'items_total' => 'Товары',
        'delivery' => 'Получение',
        'address' => 'Адрес',
        'payment' => 'Оплата',
        'delivery_price' => 'Доставка',
        'total' => 'Итого',
        'reserved_until' => 'Резерв действует до :date',
        'desired_date' => 'Желаемая дата: :date',
        'price_on_request' => 'по запросу',
        'contacts' => 'Контакты магазина',
        'customer' => 'Клиент',
        'phone' => 'Телефон',
        'email' => 'Email',
        'comment' => 'Комментарий',
        'open' => 'Открыть в админке',
        'status_text' => 'Статус вашего запроса :number изменён: :status.',
        'rights' => 'Все права защищены.',
        'default_thanks' => [
            'order' => 'Спасибо за заказ! Мы свяжемся с вами для подтверждения.',
            'reservation' => 'Спасибо! Товар зарезервирован. Мы свяжемся с вами для подтверждения.',
            'inquiry' => 'Спасибо за заявку! Мы свяжемся с вами в ближайшее время.',
        ],
    ],

    'test' => [
        'mail_sent' => 'Тестовое письмо отправлено на :email',
        'mail_failed' => 'Не удалось отправить письмо: :error',
        'mail_no_recipient' => 'Нет адреса для теста: добавьте адрес уведомлений или укажите email у вашей учётной записи.',
        'telegram_sent' => 'Тестовое сообщение отправлено в Telegram',
        'telegram_failed' => 'Не удалось отправить сообщение в Telegram: :error',
    ],

    'telegram_not_configured' => 'Telegram не настроен: укажите токен бота и Chat ID.',
];
