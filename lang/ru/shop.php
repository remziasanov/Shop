<?php

declare(strict_types=1);

return [

    'navigation' => [
        'sales' => 'Продажи',
        'catalog' => 'Каталог',
        'prices' => 'Цены',
        'content' => 'Контент',
        'settings' => 'Настройки',
        'system' => 'Система',
    ],

    'enums' => [
        'user_role' => [
            'admin' => 'Администратор',
            'manager' => 'Менеджер',
        ],
        'sales_mode' => [
            'cart' => 'Корзина',
            'reservation' => 'Резерв',
            'both' => 'Корзина и резерв',
            'inquiry' => 'Заявка',
            'showcase' => 'Витрина',
        ],
        'stock_display' => [
            'exact' => 'Число',
            'presence' => 'Есть / нет',
            'low' => 'Мало',
        ],
        'rounding_step' => [
            '0' => 'Нет',
            '1' => '1 ₽',
            '10' => '10 ₽',
            '50' => '50 ₽',
            '100' => '100 ₽',
        ],
        'rounding_direction' => [
            'up' => 'Вверх',
            'nearest' => 'К ближайшему',
            'down' => 'Вниз',
        ],
        'rounding_scope' => [
            'changed' => 'Только к ценам, изменённым правилами',
            'all' => 'Ко всем ценам',
        ],
    ],

    'validation' => [
        'rubles_amount_max' => 'Сумма не может превышать :max.',
        'rubles_amount' => 'Укажите сумму в рублях, например 1 290,50.',
    ],

    'users' => [
        'singular' => 'Пользователь',
        'plural' => 'Пользователи',
        'fields' => [
            'name' => 'Имя',
            'email' => 'Email',
            'role' => 'Роль',
            'is_active' => 'Активен',
            'password' => 'Пароль',
            'password_confirmation' => 'Повтор пароля',
            'created_at' => 'Создан',
        ],
        'password_hint' => 'Не короче 10 символов.',
        'password_hint_edit' => 'Оставьте пустым, чтобы не менять пароль. Не короче 10 символов.',
        'locked_hint' => 'Нельзя изменить роль и активность собственной записи или последнего активного администратора.',
        'errors' => [
            'self_lockout' => 'Нельзя снять с себя роль администратора или отключить собственную учётную запись.',
            'last_admin' => 'Нельзя понизить или отключить последнего активного администратора.',
        ],
    ],

    'command' => [
        'name_prompt' => 'Имя администратора',
        'email_prompt' => 'Email администратора',
        'password_prompt' => 'Пароль (не короче 10 символов)',
        'created' => 'Администратор :email создан.',
    ],

    'settings' => [
        'saved' => 'Настройки сохранены',
        'add' => 'Добавить',

        'general' => [
            'title' => 'Общие',
            'sections' => [
                'shop' => 'Магазин',
                'contacts' => 'Контакты',
                'footer' => 'Подвал',
                'status' => 'Режим работы',
            ],
            'fields' => [
                'shop_name' => 'Название магазина',
                'tagline' => 'Слоган',
                'logo' => 'Логотип',
                'favicon' => 'Фавикон',
                'phones' => 'Телефоны',
                'email' => 'Email',
                'address' => 'Адрес',
                'working_hours' => 'Режим работы',
                'social_links' => 'Соцсети и мессенджеры',
                'social_title' => 'Название',
                'social_url' => 'Ссылка',
                'footer_text' => 'Текст подвала',
                'shop_open' => 'Магазин открыт',
                'closed_message' => 'Сообщение, когда магазин закрыт',
            ],
        ],

        'appearance' => [
            'title' => 'Оформление',
            'fields' => [
                'primary_color' => 'Основной цвет',
                'accent_color' => 'Акцентный цвет',
                'grid_columns' => 'Колонок в сетке товаров',
                'per_page' => 'Товаров на странице',
                'placeholder_image' => 'Изображение-заглушка товара',
            ],
            'errors' => [
                'color_format' => 'Укажите цвет в формате #RGB или #RRGGBB, например #2563eb.',
            ],
        ],

        'sales' => [
            'title' => 'Продажи',
            'sections' => [
                'mode' => 'Режим продаж и остатки',
                'delivery' => 'Способы получения',
                'payment' => 'Способы оплаты',
                'numbering' => 'Префиксы номеров обращений',
                'thanks' => 'Тексты страницы «Спасибо»',
            ],
            'fields' => [
                'default_mode' => 'Режим по умолчанию',
                'stock_tracking' => 'Вести учёт остатков',
                'allow_backorder' => 'Разрешить «Под заказ»',
                'stock_display' => 'Как показывать наличие',
                'low_stock_threshold' => 'Порог «мало»',
                'reservation_hours' => 'Срок резерва, часов',
                'min_order_amount' => 'Минимальная сумма заказа',
                'method_name' => 'Название',
                'method_description' => 'Описание',
                'delivery_price' => 'Стоимость',
                'free_from' => 'Бесплатно от',
                'requires_address' => 'Нужен адрес',
                'is_active' => 'Активен',
                'order_prefix' => 'Заказ',
                'reservation_prefix' => 'Резерв',
                'inquiry_prefix' => 'Заявка',
                'thanks_order_text' => 'Текст после заказа',
                'thanks_reservation_text' => 'Текст после резерва',
                'thanks_inquiry_text' => 'Текст после заявки',
            ],
        ],

        'pricing' => [
            'title' => 'Цены',
            'fields' => [
                'rounding_step' => 'Шаг округления',
                'rounding_direction' => 'Направление округления',
                'rounding_scope' => 'Применять округление',
                'psychological_price' => 'Психологическая цена',
                'show_old_price' => 'Показывать старую цену',
                'show_discount_percent' => 'Показывать процент скидки',
            ],
            'hints' => [
                'psychological_price' => 'Вычитает 1 ₽ из округлённой цены, например 1 000 ₽ → 999 ₽.',
            ],
        ],

        'notifications' => [
            'title' => 'Уведомления',
            'sections' => [
                'email' => 'Email',
                'telegram' => 'Telegram',
                'events' => 'События',
            ],
            'fields' => [
                'notify_emails' => 'Адреса для уведомлений',
                'telegram_enabled' => 'Отправлять в Telegram',
                'telegram_bot_token' => 'Токен бота',
                'telegram_chat_id' => 'Chat ID',
                'remove_telegram_token' => 'Удалить токен',
                'notify_event_order' => 'Уведомлять о новых заказах',
                'notify_event_reservation' => 'Уведомлять о новых резервах',
                'notify_event_inquiry' => 'Уведомлять о новых заявках',
                'notify_shop_email' => 'Новое обращение: письмо магазину',
                'notify_shop_telegram' => 'Новое обращение: Telegram магазину',
                'notify_customer_new' => 'Новое обращение: письмо покупателю',
                'notify_customer_status' => 'Смена статуса: письмо покупателю',
                'notify_reservation_expiring' => 'Резерв скоро истекает (за 6 ч): Telegram',
                'notify_reservation_expired' => 'Резерв истёк: Telegram',
            ],
            'send_test' => 'Отправить тестовое сообщение в Telegram',
            'send_test_mail' => 'Отправить тестовое письмо',
            'token_is_set' => 'Токен задан',
            'token_hint' => 'Оставьте поле пустым, чтобы сохранить текущий токен.',
            'test_hint' => 'Тест использует введённые значения; токен и Chat ID сохраняются кнопкой «Сохранить».',
        ],

        'seo' => [
            'title' => 'SEO и счётчики',
            'sections' => [
                'templates' => 'Шаблоны товаров и категорий',
                'home' => 'Главная страница',
                'technical' => 'Технические настройки',
            ],
            'fields' => [
                'product_title_template' => 'Шаблон title товара',
                'product_description_template' => 'Шаблон description товара',
                'category_title_template' => 'Шаблон title категории',
                'category_description_template' => 'Шаблон description категории',
                'home_title' => 'Title главной',
                'home_description' => 'Description главной',
                'og_image' => 'OG-картинка по умолчанию',
                'robots_txt' => 'robots.txt',
                'verification_meta' => 'Мета-теги верификации',
                'head_code' => 'Код в head',
                'body_code' => 'Код перед </body>',
            ],
            'hints' => [
                'placeholders' => 'Доступны подстановки: {name}, {shop}, {short_description}.',
                'verification_meta' => 'По одному мета-тегу в строке.',
            ],
        ],

        'legal' => [
            'title' => 'Юридическое',
            'fields' => [
                'seller_details' => 'Реквизиты продавца',
                'consent_text' => 'Текст чекбокса согласия',
                'cookie_banner_enabled' => 'Показывать cookie-баннер',
                'cookie_banner_text' => 'Текст cookie-баннера',
                'privacy_page_id' => 'Страница политики обработки персональных данных',
                'consent_page_id' => 'Страница согласия на обработку персональных данных',
            ],
            'hints' => [
                'check_with_lawyer' => 'Шаблонный текст: проверьте у юриста.',
            ],
        ],
    ],

];
