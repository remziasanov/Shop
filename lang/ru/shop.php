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
                'mobile_columns' => 'Колонок на телефоне',
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
                'reservation_via_cart' => 'Резерв через корзину',
                'reservation_via_cart_help' => 'Товары в режиме «Резерв» и «Корзина и резерв» можно резервировать через корзину, несколько товаров одной заявкой. Выключено: резерв только одного товара из карточки.',
                'reservation_max_items' => 'Максимум позиций в резерве',
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
                'events' => 'События',
            ],
            'fields' => [
                'notify_emails' => 'Адреса для уведомлений',
                'notify_event_order' => 'Уведомлять о новых заказах',
                'notify_event_reservation' => 'Уведомлять о новых резервах',
                'notify_event_inquiry' => 'Уведомлять о новых заявках',
                'notify_shop_email' => 'Новое обращение: письмо магазину',
                'notify_shop_telegram' => 'Новое обращение: Telegram магазину',
                'notify_customer_new' => 'Новое обращение: письмо покупателю',
                'notify_customer_status' => 'Смена статуса: письмо покупателю',
                'notify_customer_order_changed' => 'Письмо покупателю при изменении состава',
                'order_discount_warning_percent' => 'Предупреждать о скидке больше, % от суммы товаров',
                'order_discount_warning_percent_help' => '0 — не предупреждать. Предупреждение показывается в редакторе состава заказа.',
                'notify_reservation_expiring' => 'Резерв скоро истекает (за 6 ч): Telegram',
                'notify_reservation_expired' => 'Резерв истёк: Telegram',
                'notify_wholesale_email' => 'Заявки на опт: email',
                'notify_wholesale_telegram' => 'Заявки на опт: Telegram',
            ],
            'send_test_mail' => 'Отправить тестовое письмо',
            'telegram_moved' => 'Токен бота, рабочий чат и тест Telegram настраиваются в «Настройки → Telegram».',
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

        'age_gate' => [
            'title' => 'Возрастное ограничение',
        ],

        'customers' => [
            'title' => 'Покупатели и регистрация',
        ],

        'catalog' => [
            'title' => 'Каталог и фильтры',
            'sections' => [
                'filters' => 'Фильтры по характеристикам',
                'seo' => 'SEO страниц фильтров',
                'import' => 'Импорт',
            ],
            'fields' => [
                'filters_max_per_category' => 'Максимум фильтров на категорию',
                'facets_cache_minutes' => 'Кэш счётчиков фильтров, минут',
                'seo_single_value_min_products' => 'Минимум товаров для карты сайта',
                'create_attributes_on_import' => 'Создавать характеристики при импорте',
            ],
            'hints' => [
                'filters_max_per_category' => 'Не больше 9: бюджет запросов страницы (FL-05, FL-10).',
                'facets_cache_minutes' => 'Сколько хранить счётчики значений; при изменении каталога кэш обновляется сразу.',
                'seo_single_value_min_products' => 'Страница «категория — одно значение» попадает в sitemap, если в ней не меньше стольких товаров.',
                'create_attributes_on_import' => 'Колонки «Х: название» с неизвестной характеристикой создают её в справочнике (без фильтра).',
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
                'bank_name' => 'Банк',
                'bik' => 'БИК',
                'account' => 'Расчётный счёт',
                'corr_account' => 'Корреспондентский счёт',
                'vat_note' => 'Пометка об НДС',
                'signature_image' => 'Подпись / печать (изображение)',
                'invoice_footer' => 'Текст сноски документа',
            ],
            'sections' => [
                'bank' => 'Банковские реквизиты и документ заказа (PDF)',
            ],
            'hints' => [
                'check_with_lawyer' => 'Шаблонный текст: проверьте у юриста.',
                'bank' => 'Если банк, БИК и расчётный счёт заполнены, PDF заказа называется «Счёт на оплату», иначе «Бланк заказа». Реквизиты продавца (название, ИНН, КПП, ОГРН, адрес) берутся из поля «Реквизиты продавца».',
                'digits' => 'Нужно ровно :count цифр.',
                'vat_note' => 'Строка в документе, по умолчанию «Без НДС». Пусто — строки нет.',
                'signature_image' => 'Необязательно. PNG или JPG до 1 МБ, хранится в закрытом каталоге.',
                'invoice_footer' => 'Необязательно. Документ не является первичным бухгалтерским документом: проверьте текст у бухгалтера.',
            ],
        ],
    ],

];
