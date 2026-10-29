<?php

declare(strict_types=1);

return [

    'resource' => [
        'singular' => 'Покупатель',
        'plural' => 'Покупатели',
    ],

    'table' => [
        'name' => 'Имя',
        'email' => 'Email',
        'phone' => 'Телефон',
        'group' => 'Группа',
        'verified' => 'Подтверждён',
        'orders' => 'Заказов',
        'purchased' => 'Сумма выкупленных',
        'registered' => 'Зарегистрирован',
        'last_login' => 'Последний вход',
        'blocked' => 'Заблокирован',
        'empty_heading' => 'Покупателей пока нет',
        'empty_description' => 'Покупатели появятся здесь после регистрации на витрине.',
    ],

    'filters' => [
        'group' => 'Группа',
        'verified' => 'Email подтверждён',
        'blocked' => 'Заблокирован',
        'has_orders' => 'Есть обращения',
        'registered' => 'Период регистрации',
        'registered_from' => 'Зарегистрирован с',
        'registered_until' => 'Зарегистрирован по',
        'indicator_from' => 'С :date',
        'indicator_until' => 'По :date',
        'yes' => 'Да',
        'no' => 'Нет',
        'all' => 'Все',
    ],

    'view' => [
        'section_main' => 'Покупатель',
        'section_status' => 'Состояние аккаунта',
        'section_company' => 'Реквизиты компании',
        'name' => 'Имя',
        'email' => 'Email',
        'phone' => 'Телефон',
        'group' => 'Группа',
        'uuid' => 'Идентификатор',
        'registered' => 'Зарегистрирован',
        'verified_at' => 'Email подтверждён',
        'not_verified' => 'Не подтверждён',
        'last_login' => 'Последний вход',
        'consent' => 'Согласие на обработку данных',
        'consent_value' => ':time, IP :ip',
        'blocked' => 'Блокировка',
        'blocked_yes' => 'Заблокирован',
        'blocked_no' => 'Нет',
        'blocked_reason' => 'Причина блокировки (видна только сотрудникам)',
        'deletion_requested' => 'Запрос на удаление',
        'deletion_none' => 'Нет',
        'anonymized' => 'Обезличен',
        'anonymized_none' => 'Нет',
        'company_none' => 'Реквизиты не заполнены.',
        'company_name' => 'Название',
        'inn' => 'ИНН',
        'kpp' => 'КПП',
        'ogrn' => 'ОГРН / ОГРНИП',
        'legal_address' => 'Юридический адрес',
        'dash' => '—',
    ],

    'edit' => [
        'saved' => 'Данные покупателя сохранены.',
        'readonly_anonymized' => 'Покупатель обезличен: данные изменить нельзя.',
    ],

    'relations' => [
        'orders' => 'Заказы',
        'addresses' => 'Адреса',
        'number' => 'Номер',
        'type' => 'Вид',
        'status' => 'Статус',
        'total' => 'Сумма',
        'created_at' => 'Создано',
        'address_label' => 'Название',
        'recipient' => 'Получатель',
        'phone' => 'Телефон',
        'address' => 'Адрес',
        'default' => 'По умолчанию',
    ],

    'actions' => [
        'change_group' => 'Сменить группу',
        'change_group_bulk' => 'Сменить группу',
        'group' => 'Новая группа',
        'block' => 'Заблокировать',
        'unblock' => 'Разблокировать',
        'block_reason' => 'Причина (видна только сотрудникам)',
        'block_heading' => 'Заблокировать покупателя?',
        'block_description' => 'Покупатель не сможет войти, все его сессии завершатся.',
        'anonymize' => 'Обезличить',
        'anonymize_heading' => 'Обезличить покупателя?',
        'anonymize_description' => 'Персональные данные будут стёрты без возможности восстановления. Заказы останутся для учёта без персональных данных.',
        'resend_code' => 'Отправить код подтверждения',
        'resend_description' => 'Покупателю уйдёт новое письмо с кодом подтверждения email.',
    ],

    'notifications' => [
        'group_changed' => 'Группа изменена.',
        'groups_changed' => 'Группа изменена у покупателей: :count.',
        'blocked' => 'Покупатель заблокирован.',
        'unblocked' => 'Покупатель разблокирован.',
        'anonymized' => 'Покупатель обезличен.',
        'already_anonymized' => 'Покупатель уже обезличен.',
        'code_sent' => 'Код подтверждения отправлен.',
        'forbidden' => 'Недостаточно прав для этого действия.',
    ],

    'errors' => [
        'group_not_assignable' => 'Эту группу нельзя назначить покупателю.',
        'anonymized' => 'Покупатель обезличен, действие недоступно.',
        'already_verified' => 'Email покупателя уже подтверждён.',
    ],

    'widget' => [
        'new_week' => 'Новые регистрации за 7 дней',
        'new_week_hint' => 'Сегодня: :today',
        'unverified' => 'Не подтвердили email',
        'unverified_hint' => 'Из зарегистрированных за 7 дней',
    ],

    'command' => [
        'anonymized' => 'Обезличено покупателей: :count',
        'purged_unverified' => 'Удалено неподтверждённых аккаунтов: :count',
        'purged_codes' => 'Удалено устаревших кодов: :count',
    ],

];
