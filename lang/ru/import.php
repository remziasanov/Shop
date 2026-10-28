<?php

declare(strict_types=1);

return [
    'statuses' => [
        'uploaded' => 'Загружен',
        'analyzing' => 'Проверка файла',
        'analyzed' => 'Проверен',
        'queued' => 'В очереди',
        'processing' => 'Обработка',
        'completed' => 'Завершён',
        'completed_with_errors' => 'Завершён с ошибками',
        'failed' => 'Сбой',
        'cancelled' => 'Отменён',
    ],

    'modes' => [
        'create_update' => 'Создать и обновить',
        'prices_stock' => 'Только обновить цены и остатки',
        'full_sync' => 'Полная синхронизация',
    ],

    'empty_cell' => [
        'keep' => 'Не менять',
        'clear' => 'Очистить',
    ],

    'photo_mode' => [
        'replace' => 'Заменить, если в ячейке есть фото',
        'append' => 'Добавить к существующим',
        'skip' => 'Не трогать',
    ],

    'media_source' => [
        'none' => 'Без архива фото',
        'zip' => 'ZIP-архив',
        'server_dir' => 'Папка на сервере',
    ],

    'issue_level' => [
        'error' => 'Ошибка',
        'warning' => 'Предупреждение',
    ],

    'export_types' => [
        'catalog' => 'Каталог',
        'orders' => 'Обращения',
    ],

    'export_statuses' => [
        'queued' => 'В очереди',
        'processing' => 'Формируется',
        'completed' => 'Готов',
        'failed' => 'Сбой',
    ],

    'category_separators' => [
        '/' => '/ (косая черта)',
        '>' => '> (стрелка)',
        '|' => '| (вертикальная черта)',
    ],

    'fields' => [
        'sku' => 'Артикул',
        'name' => 'Название',
        'category' => 'Категория',
        'price' => 'Цена',
        'cost_price' => 'Закупочная цена',
        'old_price' => 'Старая цена',
        'stock' => 'Остаток',
        'unit' => 'Единица',
        'brand' => 'Бренд',
        'short_description' => 'Краткое описание',
        'description' => 'Описание',
        'photos' => 'Фото',
        'active' => 'Активен',
        'featured' => 'Хит',
        'new' => 'Новинка',
        'sales_mode' => 'Режим продажи',
        'price_on_request' => 'Цена по запросу',
        'sort' => 'Порядок',
        'meta_title' => 'Meta title',
        'meta_description' => 'Meta description',
        'age' => 'Возраст',
    ],

    'category_fields' => [
        'path' => 'Путь',
        'description' => 'Описание',
        'sort' => 'Порядок',
        'active' => 'Активна',
        'sales_mode' => 'Режим продажи',
        'photo' => 'Фото',
        'meta_title' => 'Meta title',
        'meta_description' => 'Meta description',
        'age' => 'Возраст',
    ],

    'mapping' => [
        'ignored' => 'Не импортировать',
        'attribute' => 'Характеристика: :name',
        'duplicate' => 'Колонка «:header» пропущена: поле «:field» уже назначено колонке «:first».',
    ],

    'errors' => [
        'file_unreadable' => 'Не удалось прочитать файл. Загрузите файл .xlsx или .csv.',
        'xls_unsupported' => 'Старый формат .xls не поддерживается. Сохраните файл как .xlsx или .csv.',
        'sheet_not_found' => 'В файле нет листа «:sheet».',
        'empty_file' => 'В файле нет данных: первая строка должна содержать заголовки.',
        'no_sku_column' => 'Не найдена колонка с артикулом. Назначьте колонку «Артикул».',
        'analysis_failed' => 'Не удалось проверить файл.',

        'required' => 'Поле «:field» обязательно.',
        'required_new' => 'Для нового товара обязательно поле «:field».',
        'too_long' => 'Поле «:field» длиннее :max символов.',
        'invalid_price' => 'Цена должна быть числом не меньше 0, например «1 290,50».',
        'price_too_big' => 'Слишком большая цена.',
        'invalid_stock' => 'Остаток должен быть целым числом не меньше 0.',
        'invalid_integer' => 'Значение должно быть целым числом.',
        'invalid_bool' => 'Допустимые значения: да / нет / 1 / 0 / true / false / + / −.',
        'invalid_age' => 'Допустимые значения: 16, 18, пусто (наследовать) или 0 (очистить).',
        'invalid_sales_mode' => 'Допустимые значения: корзина, резерв, корзина и резерв, заявка, витрина, по умолчанию.',
        'duplicate_sku' => 'Артикул повторяется в файле (первая строка: :row).',
        'category_too_deep' => 'Путь категории глубже :max уровней.',
        'photo_too_long' => 'Ссылка или имя файла фото длиннее :max символов.',
    ],

    'warnings' => [
        'nothing_to_update' => 'В строке нет цены и остатка для обновления.',
        'old_price_not_greater' => 'Старая цена не больше текущей — зачёркнутая цена показана не будет.',
        'attribute_empty_name' => 'Характеристика без названия пропущена.',
    ],
];
