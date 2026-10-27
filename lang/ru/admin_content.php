<?php

declare(strict_types=1);

return [

    'pages' => [
        'singular' => 'Страница',
        'plural' => 'Страницы',
        'fields' => [
            'title' => 'Заголовок',
            'slug' => 'Slug (адрес)',
            'slug_hint' => 'Можно оставить пустым: адрес будет создан автоматически из заголовка. Страница доступна по адресу /page/{slug}.',
            'content' => 'Содержимое',
            'is_active' => 'Активна',
            'meta_title' => 'SEO title',
            'meta_description' => 'SEO description',
        ],
        'sections' => [
            'main' => 'Основное',
            'seo' => 'SEO',
        ],
        'table' => [
            'title' => 'Заголовок',
            'slug' => 'Адрес',
            'is_active' => 'Активна',
            'updated_at' => 'Изменена',
            'draft' => 'Черновик',
        ],
        'actions' => [
            'open_on_site' => 'Открыть на сайте',
        ],
    ],

    'menu' => [
        'singular' => 'Пункт меню',
        'plural' => 'Меню',
        'locations' => [
            'header' => 'Шапка',
            'footer' => 'Подвал',
        ],
        'link_types' => [
            'category' => 'Категория',
            'page' => 'Страница',
            'url' => 'Произвольный URL',
        ],
        'fields' => [
            'location' => 'Меню',
            'label' => 'Подпись',
            'link_type' => 'Тип ссылки',
            'category' => 'Категория',
            'page' => 'Страница',
            'url' => 'URL',
            'url_hint' => 'http(s)://…, путь на сайте (/catalog), tel: или mailto:.',
            'url_invalid' => 'Допустимы только адреса http(s)://…, пути вида /catalog, tel: и mailto:.',
            'sort_order' => 'Порядок',
            'is_active' => 'Активен',
        ],
        'table' => [
            'label' => 'Подпись',
            'link_type' => 'Тип',
            'target' => 'Ссылка',
            'is_active' => 'Активен',
            'reorder_hint' => 'Порядок меняется перетаскиванием. Если меню шапки пусто, в шапке показываются корневые категории. У пункта-категории подкатегории показываются выпадающим списком.',
        ],
    ],

];
