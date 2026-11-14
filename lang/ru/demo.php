<?php

declare(strict_types=1);

return [
    'production_refused' => 'Демо-данные нельзя создавать в production. Если вы уверены, запустите команду с флагом --force.',
    'fresh_done' => 'Удалено демо-данных: товаров — :products, категорий — :categories.',
    'summary_title' => 'Демо-данные готовы',
    'categories' => 'Категории: создано :created, обновлено :updated.',
    'products' => 'Товары: создано :created, обновлено :updated.',
    'wholesale' => 'Версия 2.0: оптовых цен :prices, модификаций создано :variants, промо создано :promo, наборов фильтров :filters.',
    'images' => 'Фото: создано :created, пропущено (уже есть) :skipped.',
    'images_disabled' => 'Фото: пропущены (--no-images).',
    'warning' => 'Предупреждение: :message',
    'warnings_count' => 'Предупреждений: :count.',
    'invalid_limit' => 'Параметр --limit должен быть положительным целым числом.',
];
