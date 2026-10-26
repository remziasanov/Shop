<?php

declare(strict_types=1);

return [

    'types' => [
        'order' => 'Заказ',
        'reservation' => 'Резерв',
        'inquiry' => 'Заявка',
    ],

    'types_plural' => [
        'order' => 'Заказы',
        'reservation' => 'Резервы',
        'inquiry' => 'Заявки',
    ],

    'status' => [
        'default' => [
            'new' => 'Новый',
            'confirmed' => 'Подтверждён',
            'ready' => 'Готов к выдаче',
            'shipped' => 'Отправлен',
            'completed' => 'Выполнен',
            'cancelled' => 'Отменён',
            'reserved' => 'Зарезервирован (подтверждён)',
            'expired' => 'Истёк',
            'in_progress' => 'В работе',
            'closed' => 'Закрыта',
        ],
        'order' => [
            'new' => 'Новый',
            'confirmed' => 'Подтверждён',
            'ready' => 'Готов к выдаче',
            'shipped' => 'Отправлен',
            'completed' => 'Выполнен',
            'cancelled' => 'Отменён',
        ],
        'reservation' => [
            'new' => 'Новый',
            'reserved' => 'Зарезервирован (подтверждён)',
            'completed' => 'Выкуплен',
            'cancelled' => 'Отменён',
            'expired' => 'Истёк',
        ],
        'inquiry' => [
            'new' => 'Новая',
            'in_progress' => 'В работе',
            'closed' => 'Закрыта',
        ],
    ],

    'actions' => [
        'out_of_stock' => 'Нет в наличии',
        'backorder' => 'Заказать (под заказ)',
    ],

    'history' => [
        'created' => 'Обращение создано',
        'expired' => 'Срок резерва истёк',
        'extended' => 'Продлён на :hours ч',
        'new_reserved_until' => 'Новый срок резерва: :date',
    ],

    'errors' => [
        'consent_required' => 'Необходимо согласие на обработку персональных данных.',
        'name_required' => 'Укажите имя.',
        'phone_invalid' => 'Укажите корректный номер телефона.',
        'cart_empty' => 'Корзина пуста.',
        'delivery_invalid' => 'Выберите способ получения.',
        'address_required' => 'Укажите адрес доставки.',
        'payment_invalid' => 'Выберите способ оплаты.',
        'min_order' => 'Минимальная сумма заказа — :min. Добавьте товаров ещё на :missing.',
        'qty_invalid' => 'Укажите корректное количество.',
        'product_unavailable' => 'Товар недоступен.',
        'reservation_unavailable' => 'Этот товар нельзя зарезервировать.',
        'inquiry_unavailable' => 'По этому товару нельзя оставить заявку.',
        'reservation_qty' => 'Можно зарезервировать не более :max.',
        'desired_date' => 'Желаемая дата забора не может быть раньше сегодняшнего дня и позже срока резерва.',
        'insufficient_stock' => 'Недостаточно товара «:name»: доступно :available.',
        'transition' => 'Переход из статуса «:from» в «:to» недопустим.',
        'extend_only_reservation' => 'Продлить можно только действующий резерв.',
        'extend_hours' => 'Укажите положительное число часов.',
        'reserved_until_past' => 'Срок резерва должен быть в будущем.',
    ],

    'cart' => [
        'not_available' => 'Товар «:name» нельзя добавить в корзину.',
        'out_of_stock' => 'Товара «:name» нет в наличии.',
        'qty_invalid' => 'Количество должно быть не меньше 1.',
        'not_in_cart' => 'Товара нет в корзине.',
        'issue_unavailable' => 'Товар больше недоступен для заказа.',
        'issue_exceeds' => 'В наличии только :max.',
    ],

    'checkout' => [
        'changed_intro' => 'Корзина изменилась, проверьте заказ.',
        'removed_unavailable' => 'Товар «:name» больше недоступен и удалён из корзины.',
        'removed_out_of_stock' => 'Товара «:name» нет в наличии, он удалён из корзины.',
        'qty_reduced' => 'Количество товара «:name» уменьшено до :qty (столько осталось в наличии).',
        'price_changed' => 'Цена товара «:name» изменилась: было :old, стало :new.',
    ],

    'guard' => [
        'generic' => 'Не удалось отправить форму. Обновите страницу и попробуйте ещё раз.',
        'too_fast' => 'Слишком быстро. Подождите несколько секунд и отправьте форму ещё раз.',
        'rate_limited' => 'Слишком много отправок. Попробуйте через минуту.',
    ],

];
