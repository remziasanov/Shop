<?php

declare(strict_types=1);

return [

    'link_purpose' => [
        'customer' => 'Привязка к покупателю',
        'staff' => 'Привязка к сотруднику',
        'order' => 'Статусы обращения',
    ],

    // Commands of the bot menu (setMyCommands, TG-02).
    'commands' => [
        'start' => 'Начать',
        'orders' => 'Мои заказы',
        'help' => 'Помощь',
        'stop' => 'Отключить уведомления',
    ],

    // Replies of the bot (TG-05).
    'bot' => [
        'open_shop' => 'Открыть магазин',
        'my_orders' => 'Мои заказы',
        'workplace' => 'Рабочее место',
        'welcome' => 'Здравствуйте! Это бот магазина «:shop». Нажмите «Открыть магазин», чтобы посмотреть каталог и оформить заказ. Статусы ваших заказов будут приходить сюда.',
        'help' => "Что умеет бот:\n/start — открыть магазин\n/orders — ваши заказы\n/stop — отключить уведомления\n\nУведомления можно включить снова командой /start.",
        'orders_title' => 'Ваши последние обращения:',
        'orders_empty' => 'Пока нет обращений, привязанных к этому Telegram. Откройте магазин, чтобы оформить заказ.',
        'stopped' => 'Уведомления отключены. Включить — /start',
        'autoreply' => 'Откройте магазин или позвоните :phone.',
        'autoreply_no_phone' => 'Откройте магазин или свяжитесь с нами другим способом.',
        'contact_saved' => 'Спасибо, номер получен. Вернитесь в магазин.',
        'contact_not_own' => 'Это не ваш номер. Отправьте свой собственный контакт.',
    ],

    'callback' => [
        'soon' => 'Скоро',
    ],

    // Linking (TG-07, TG-08).
    'link' => [
        'expired' => 'Ссылка устарела, получите новую на сайте.',
        'expired_toast' => 'Ссылка устарела',
        'already_linked_customer' => 'Этот Telegram уже подключён к другому аккаунту. Отключите его там или войдите через Mini App.',
        'already_linked_staff' => 'Этот Telegram уже подключён к другому сотруднику.',
        'already_yours' => 'Этот Telegram уже подключён к вашему аккаунту.',
        'confirm_customer' => 'Подключить этот Telegram к аккаунту :email?',
        'confirm_staff' => 'Подключить этот Telegram к сотруднику :name?',
        'button_connect' => 'Подключить',
        'button_decline' => 'Это не я',
        'declined' => 'Хорошо, Telegram не подключён. Эта ссылка больше не действует.',
        'declined_toast' => 'Отменено',
        'done_customer' => 'Готово! Telegram подключён к вашему аккаунту. Статусы заказов будут приходить сюда.',
        'done_staff' => 'Готово! Telegram подключён к вашей учётной записи сотрудника.',
        'done_toast' => 'Подключено',
        'failed' => 'Не удалось подключить Telegram. Получите новую ссылку на сайте.',
        'failed_toast' => 'Ошибка',
        'orders_followed' => 'Готово! Статусы этих обращений будут приходить сюда:',
        'no_username' => 'без имени пользователя',
        'cabinet_title' => 'К аккаунту подключён Telegram',
        'cabinet_body' => 'К аккаунту подключён Telegram :telegram. Если это были не вы, отключите его в разделе «Уведомления».',
    ],

    'mail' => [
        'subject' => 'К вашей учётной записи подключён Telegram',
        'title' => 'Подключён Telegram',
        'text' => 'К аккаунту подключён Telegram :telegram. Если это не вы — отключите Telegram в кабинете, раздел «Уведомления».',
        'text_staff' => 'К вашей учётной записи подключён Telegram :telegram. Если это не вы — отключите Telegram в своём профиле и сообщите администратору.',
    ],

    // Cabinet, thanks page and e-mail (TG-07, TG-08, TG-11).
    'cabinet' => [
        'title' => 'Telegram',
        'text' => 'Получайте статусы заказов в Telegram.',
        'connect' => 'Подключить Telegram',
        'connected' => 'Telegram подключён',
        'disconnect' => 'Отключить Telegram',
        'disconnected' => 'Telegram отключён.',
        'start_hint' => 'Нажмите /start в боте, чтобы получать уведомления. Если бот был заменён, нажмите /start в новом боте.',
        'link_unavailable' => 'Сейчас подключить Telegram нельзя.',
    ],

    'order_link' => [
        'button' => 'Получать статус в Telegram',
        'hint' => 'Бот пришлёт номер обращения и сообщит, когда изменится статус.',
    ],

    'staff' => [
        'title' => 'Telegram',
        'connect' => 'Подключить Telegram',
        'connected' => 'Telegram подключён',
        'disconnect' => 'Отключить Telegram',
        'link_ready' => 'Откройте ссылку в Telegram в течение 15 минут и подтвердите привязку в боте.',
        'link_open' => 'Открыть бота',
        'unavailable' => 'Бот не подключён: сначала подключите бота в «Настройки → Telegram».',
        'disconnected' => 'Telegram отключён.',
        'page_title' => 'Мой профиль: Telegram',
        'menu' => 'Мой Telegram',
    ],

    // «Настройки → Telegram» (TG-01…TG-03, TG-11).
    'admin' => [
        'https_required' => 'Подключить бота можно только при адресе сайта с https:// (APP_URL). Получите бесплатный сертификат в панели хостинга и обновите APP_URL.',
        'bot_changed' => 'Бот сменился: у всех пользователей сброшено «чат начат», им нужно нажать /start в новом боте.',
        'menu_button_default' => 'Магазин',
        'work_chat_removed' => 'Бот удалён из рабочего чата.',
        'work_chat_error' => 'Рабочий чат: :error',
        'connect_done' => 'Бот подключён',
        'connect_failed' => 'Не удалось подключить бота',
        'disconnected' => 'Бот отключён',
        'seconds' => 'с',
        'sections' => [
            'connection' => 'Бот и рабочий чат',
            'texts' => 'Тексты',
            'features' => 'Функции',
            'startapp' => 'Ссылки на Mini App (Main Mini App)',
        ],
        'fields' => [
            'change_token' => 'Изменить токен',
            'bot_token' => 'Токен бота',
            'bot_token_help' => 'Токен от @BotFather. Он хранится зашифрованным и не показывается после сохранения.',
            'work_chat_id' => 'Рабочий чат магазина',
            'work_chat_id_help' => 'Chat ID группы (например -1001234567890) или @канал.',
            'enabled' => 'Отправлять уведомления в рабочий чат',
            'enabled_help' => 'Какие события отправлять — в «Настройки → Уведомления».',
            'bot_username' => 'Имя бота',
            'bot_id' => 'ID бота',
            'menu_button_text' => 'Текст кнопки меню',
            'welcome_text' => 'Приветствие для /start',
            'help_text' => 'Текст /help',
            'default_text_help' => 'Оставьте пустым, чтобы использовать стандартный текст.',
            'miniapp_enabled' => 'Mini App для покупателей',
            'autologin_enabled' => 'Автоматический вход в Mini App по Telegram',
            'customer_notifications_enabled' => 'Уведомления покупателям в Telegram',
            'work_chat_buttons_enabled' => 'Кнопки действий в рабочем чате',
            'staff_workplace_enabled' => 'Mini App «Рабочее место» для сотрудников',
            'miniapp_colors' => 'Оформление Mini App',
            'initdata_ttl_customer' => 'Срок подписи Telegram: покупатель',
            'initdata_ttl_staff' => 'Срок подписи Telegram: сотрудник',
            'initdata_ttl_help' => 'Подпись проверяется только при открытии Mini App; Telegram выдаёт новую при каждом запуске.',
        ],
        'colors' => [
            'shop' => 'Цвета магазина',
            'telegram' => 'Цвета Telegram',
        ],
        'actions' => [
            'connect' => 'Подключить бота',
            'check' => 'Проверить',
            'disconnect' => 'Отключить',
            'test' => 'Отправить тест в рабочий чат',
        ],
        'state' => [
            'status' => 'Состояние',
            'connected' => 'Подключено',
            'token_set' => 'Токен задан, бот не подключён',
            'not_connected' => 'Не подключено',
            'webhook_url' => 'Адрес вебхука',
            'pending' => 'Ожидающих обновлений',
            'last_error' => 'Последняя ошибка',
            'no_errors' => 'Ошибок нет',
            'none' => 'не установлен',
        ],
        'steps' => [
            'https' => 'Проверка адреса сайта',
            'getMe' => 'Проверка токена (getMe)',
            'bot_changed' => 'Смена бота',
            'setWebhook' => 'Вебхук (setWebhook)',
            'setMyCommands' => 'Команды бота (setMyCommands)',
            'setChatMenuButton' => 'Кнопка меню (setChatMenuButton)',
            'deleteWebhook' => 'Вебхук удалён (deleteWebhook)',
        ],
        'startapp' => [
            'intro' => 'Ссылки вида t.me/бот?startapp=… работают, только если у бота включено «основное приложение». Это делается вручную в @BotFather, через Bot API это сделать нельзя.',
            'step1' => 'Откройте @BotFather и отправьте /mybots.',
            'step2' => 'Выберите бота: Bot Settings → Configure Mini App (или Main Mini App).',
            'step3' => 'Нажмите Enable и вставьте ссылку:',
            'step4' => 'Вернитесь сюда и нажмите «Проверить ссылку».',
            'check' => 'Проверить ссылку',
            'connect_first' => 'Сначала подключите бота.',
            'verified' => 'Ссылки работают',
            'not_verified' => 'Ссылки ещё не проверены: сайт использует кнопку web_app и ссылки /start.',
        ],
    ],

    'command' => [
        'pruned' => 'Удалено: обновлений :updates, токенов :tokens, сообщений :messages, аккаунтов :accounts.',
    ],

    // shop:doctor (TG-12).
    'doctor' => [
        'not_connected' => 'бот не подключён',
        'https_ok' => 'https',
        'webhook_ok' => 'вебхук установлен: :url',
        'webhook_missing' => 'вебхук не установлен',
        'webhook_wrong_url' => 'вебхук смотрит на :url',
        'pending' => ':count в очереди',
        'webhook_error_value' => ':error',
        'no_errors' => 'ошибок нет',
        'startapp_ok' => 'ссылки работают',
        'startapp_missing' => 'не подтверждены',
        'work_chat_ok' => 'без ошибок',
        'api_failed' => 'нет ответа Telegram: :error',
        'hints' => [
            'https' => 'Telegram принимает вебхук только по https. Укажите https:// в APP_URL.',
            'webhook' => 'Нажмите «Подключить бота» в «Настройки → Telegram».',
            'pending' => 'Telegram копит необработанные обновления: проверьте, что сайт отвечает на /telegram/webhook.',
            'webhook_error' => 'Последняя ошибка вебхука за 24 часа. Проверьте адрес и сертификат.',
            'startapp' => 'Включите основное приложение в @BotFather (см. «Настройки → Telegram»).',
            'work_chat' => 'Проверьте, что бот добавлен в рабочий чат, и Chat ID верный.',
        ],
    ],

];
