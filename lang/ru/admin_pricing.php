<?php

declare(strict_types=1);

return [
    'rules' => [
        'singular' => 'Правило цен',
        'plural' => 'Правила цен',

        'sections' => [
            'rule' => 'Правило',
            'scope' => 'Область применения',
            'period' => 'Период действия',
            'period_hint' => 'Пусто — без ограничений. Правило действует с начала периода и до его окончания (конец не включается).',
        ],

        'fields' => [
            'name' => 'Название',
            'is_active' => 'Активно',
            'action' => 'Действие',
            'value' => 'Значение',
            'value_hint_percent' => 'Больше 0, до 4 знаков после запятой.',
            'value_hint_rubles' => 'Сумма в рублях, больше 0, до 2 знаков после запятой.',
            'scope' => 'Область применения',
            'categories' => 'Категории',
            'include_subcategories' => 'Включая подкатегории',
            'products' => 'Товары',
            'brands' => 'Бренды',
            'brands_hint' => 'Регистр не важен. Выберите из подсказок или введите название и нажмите Enter.',
            'flags' => 'Метки',
            'priority' => 'Приоритет',
            'priority_hint' => 'Больше — раньше. При равенстве раньше применяется более старое правило.',
            'starts_at' => 'Начало',
            'ends_at' => 'Окончание',
            'show_as_discount' => 'Показывать как скидку',
            'show_as_discount_hint' => 'Цена до скидки станет «старой ценой», на витрине появится метка скидки.',
            'stop_processing' => 'Остановить обработку',
            'stop_processing_hint' => 'Следующие правила этой фазы (наценки или скидки) не применяются.',
        ],

        'table' => [
            'action' => 'Действие',
            'period' => 'Период',
            'updated_at' => 'Изменено',
        ],

        'filters' => [
            'active' => 'Активность',
            'in_effect' => 'Действует сейчас',
        ],

        // :value is the amount with its unit ("10 %", "500 ₽").
        'summary' => [
            'markup_percent' => 'Наценка :value',
            'markup_fixed' => 'Наценка :value',
            'cost_markup_percent' => 'Наценка от закупочной :value',
            'discount_percent' => 'Скидка :value',
            'discount_fixed' => 'Скидка :value',
        ],

        'with_subcategories' => '(+ подкатегории)',
        'products_count' => ':count шт.',
        'and_more' => 'и ещё :count',
        'unlimited' => 'Без ограничений',
        'period_from' => 'с :date',
        'period_to' => 'по :date',
    ],

    'preview' => [
        'heading' => 'Предпросмотр',
        'hint' => 'Сколько товаров затронет правило и как изменятся цены первых 20. Правило сохранять не нужно.',
        'button' => 'Предпросмотр',
        'close' => 'Закрыть',
        'count' => 'Затронуто товаров: :count',
        'none' => 'Под правило не подходит ни один товар.',
        'incomplete' => 'Заполните действие, значение и область применения.',
        'product' => 'Товар',
        'sku' => 'Артикул',
        'before' => 'Сейчас',
        'after' => 'Будет',
    ],

    'history' => [
        'singular' => 'Изменение цены',
        'plural' => 'История цен',
        'heading' => 'История цен',
        'empty' => 'Изменений цен пока не было.',
        'open_all' => 'Вся история цен товара',

        'columns' => [
            'created_at' => 'Дата',
            'product' => 'Товар',
            'field' => 'Цена',
            'change' => 'Было → стало',
            'source' => 'Источник',
            'user' => 'Пользователь',
        ],

        'fields' => [
            'base_price' => 'Базовая цена',
            'cost_price' => 'Закупочная цена',
            'fixed_price' => 'Фиксированная цена',
            'compare_at_price' => 'Старая цена',
        ],

        'filters' => [
            'product' => 'Товар (название или артикул)',
            'from' => 'С',
            'until' => 'По',
        ],
    ],

    'bulk' => [
        'label' => 'Изменить цену',
        'heading' => 'Массовое изменение базовой цены',
        'step_params' => 'Параметры',
        'step_preview' => 'Предпросмотр',
        'field' => 'Поле',
        'field_base_price' => 'Базовая цена',
        'operation' => 'Действие',
        'value' => 'Значение',
        'apply' => 'Применить',
        'summary' => 'Выбрано товаров: :count. Показаны первые :shown.',
        'done' => 'Цена изменена у товаров: :count',

        'operations' => [
            'increase_percent' => 'Увеличить на %',
            'decrease_percent' => 'Уменьшить на %',
            'increase_fixed' => 'Увеличить на ₽',
            'decrease_fixed' => 'Уменьшить на ₽',
        ],
    ],

    'validation' => [
        'positive' => 'Введите число больше нуля.',
        'decimals' => 'Слишком много знаков после запятой (допустимо до :decimals), в целой части не более 8 цифр.',
        'max' => 'Значение не должно быть больше :max.',
        'ends_after_starts' => 'Окончание должно быть позже начала.',
    ],
];
