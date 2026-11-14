<?php

declare(strict_types=1);

return [

    // Button labels (TS-01) and the second tap (TS-04).
    'action' => [
        'confirm' => 'Подтвердить',
        'confirm_reservation' => 'Подтвердить резерв',
        'ready' => 'Готов к выдаче',
        'inprogress' => 'В работу',
        'extend' => 'Продлить на :hours ч',
        'close' => 'Закрыть',
        'cancel' => 'Отменить',
        'cancel_sure' => 'Точно отменить?',
        'open_admin' => 'Открыть в админке',
        'workplace' => 'Рабочее место',
    ],

    // «Подтвердил Иванов 14:32» under the message (TS-03).
    'done_by' => [
        'confirmed' => 'Подтвердил',
        'reserved' => 'Подтвердил резерв',
        'ready' => 'Отметил «Готов к выдаче»',
        'shipped' => 'Отметил «Отгружен»',
        'completed' => 'Завершил',
        'cancelled' => 'Отменил',
        'in_progress' => 'Взял в работу',
        'closed' => 'Закрыл',
        'expired' => 'Резерв истёк',
        'extended' => 'Продлил резерв',
        'default' => 'Изменил статус',
    ],
    'status_line' => 'Статус',

    // Pop-ups of the buttons (answerCallbackQuery, at most 200 characters).
    'toast' => [
        'not_linked' => 'Подключите Telegram в профиле админки',
        'wrong_chat' => 'Кнопка недоступна в этом чате',
        'disabled' => 'Кнопки действий отключены в настройках',
        'forbidden' => 'Недостаточно прав для этого действия',
        'not_found' => 'Обращение не найдено',
        'stale' => 'Обращение уже изменено',
        'confirm_cancel' => 'Нажмите ещё раз в течение 30 секунд, чтобы отменить',
        'failed' => 'Не удалось выполнить действие',
        'bad_data' => 'Кнопка устарела',
        'done_confirmed' => 'Подтверждено',
        'done_reserved' => 'Резерв подтверждён',
        'done_ready' => 'Отмечено: готов к выдаче',
        'done_in_progress' => 'Взято в работу',
        'done_closed' => 'Заявка закрыта',
        'done_cancelled' => 'Обращение отменено',
        'done_extended' => 'Резерв продлён до :date',
        'done_default' => 'Готово',
    ],

    // /start staff (TS-01, TS-05).
    'bot' => [
        'workplace_text' => 'Откройте «Рабочее место», чтобы работать с обращениями.',
        'not_linked' => 'Подключите Telegram в профиле админки (меню пользователя → «Мой Telegram»).',
        'unavailable' => 'Рабочее место недоступно: функция отключена или у вас нет прав на просмотр обращений.',
    ],

    // Personal notifications (TS-08) and the profile.
    'personal' => [
        'toggle' => 'Присылать новые обращения лично в Telegram',
        'toggle_help' => 'Бот напишет вам в личный чат о каждом новом обращении, если у вас есть право на просмотр обращений.',
        'saved' => 'Настройка сохранена.',
        'needs_link' => 'Сначала подключите Telegram.',
    ],

    // «Пользователи»: the linked Telegram (TS-02).
    'users' => [
        'column' => 'Telegram',
        'not_linked' => 'не подключён',
        'unlink' => 'Отвязать Telegram',
        'unlink_confirm' => 'Сотрудник потеряет доступ к кнопкам в Telegram и к «Рабочему месту».',
        'unlinked' => 'Telegram отвязан.',
    ],

    // The admin order card: the work chat messages.
    'admin' => [
        'section' => 'Сообщения сотрудникам в Telegram',
        'chat' => 'Рабочий чат',
        'personal' => 'Личные сообщения',
        'when' => 'Когда',
        'where' => 'Куда',
        'status' => 'Статус',
    ],

    // The Mini App «Рабочее место» (TS-05, TS-06).
    'workplace' => [
        'title' => 'Рабочее место',
        'denied_title' => 'Нет доступа',
        'denied_text' => 'Доступ к «Рабочему месту» закрыт. Проверьте привязку Telegram и права в админке.',
        'denied_button' => 'Открыть бота',
        'tabs' => [
            'new' => 'Новые',
            'active' => 'В работе',
            'expiring' => 'Резервы: истекают сегодня',
            'all' => 'Все',
        ],
        'search' => 'Номер, телефон или имя',
        'search_button' => 'Найти',
        'empty' => 'Обращений нет',
        'more' => 'Показать ещё',
        'back' => 'К списку',
        'open_admin' => 'Открыть в админке',
        'call' => 'Позвонить',
        'customer' => 'Клиент',
        'phone' => 'Телефон',
        'channel' => 'Канал',
        'items' => 'Состав',
        'total' => 'Сумма',
        'reserved_until' => 'Резерв до',
        'customer_comment' => 'Комментарий покупателя',
        'manager_comment' => 'Комментарий менеджера',
        'manager_comment_save' => 'Сохранить комментарий',
        'manager_comment_saved' => 'Комментарий сохранён',
        'history' => 'История статусов',
        'messages' => 'Сообщения в Telegram',
        'no_messages' => 'Сообщений нет',
        'actions' => 'Действия',
        'extend_hours' => 'Часов',
        'extend' => 'Продлить',
        'no_actions' => 'Для этого статуса действий нет',
        'cancel_sure' => 'Точно отменить?',
        'view_only' => 'У вас нет права менять обращения: доступен только просмотр.',
        'by' => 'кто',
    ],

    // Audit log of staff actions from Telegram (TS-07): what the data holds.
    'via' => [
        'chat' => 'кнопка в чате',
        'workplace' => 'Рабочее место',
    ],
];
