<?php

declare(strict_types=1);

return [

    'singular' => 'Категория',
    'plural' => 'Категории',

    'fields' => [
        'name' => 'Название',
        'slug' => 'Slug (адрес)',
        'slug_hint' => 'Можно оставить пустым: адрес будет создан автоматически из названия.',
        'parent' => 'Родительская категория',
        'parent_placeholder' => 'Без родителя (корневая)',
        'description' => 'Описание',
        'image' => 'Изображение',
        'image_hint' => 'JPG, PNG, WEBP или GIF, до 10 МБ.',
        'is_active' => 'Активна',
        'sales_mode' => 'Режим продаж',
        'sales_mode_inherit' => 'Наследовать',
        'meta_title' => 'SEO title',
        'meta_description' => 'SEO description',
        'sort_order' => 'Порядок',
        'sort_order_hint' => 'Меньше число — выше в списке. Удобнее менять порядок перетаскиванием в списке.',
    ],

    'sections' => [
        'main' => 'Основное',
        'seo' => 'SEO',
    ],

    'table' => [
        'name' => 'Название',
        'products_count' => 'Товаров',
        'products_count_hint' => 'С учётом подкатегорий',
        'sales_mode' => 'Режим продаж',
        'sales_mode_inherit' => 'Наследовать',
        'is_active' => 'Активна',
        'filter_parent' => 'Родитель',
        'filter_root' => 'Корневые категории',
        'reorder_hint' => 'Чтобы менять порядок перетаскиванием, выберите родителя в фильтре.',
    ],

    'actions' => [
        'open_on_site' => 'Открыть на сайте',
        'delete_moving' => 'Перенести товары и подкатегории в родительскую категорию и удалить?',
        'delete_moving_description' => 'В категории: подкатегорий — :children, товаров — :products. Они будут перенесены в родительскую категорию, после чего категория будет удалена.',
        'delete_moving_submit' => 'Перенести и удалить',
        'delete_plain' => 'Удалить категорию?',
        'bulk_delete' => 'Удалить выбранные',
        'bulk_deleted' => 'Удалено категорий: :count',
        'bulk_skipped' => 'Не удалены категории с товарами или подкатегориями (:count). Удалите их по одной с переносом в родительскую категорию.',
    ],

    'notifications' => [
        'deleted' => 'Категория удалена',
        'deleted_moved' => 'Категория удалена, товары и подкатегории перенесены в родительскую категорию',
        'delete_failed' => 'Не удалось удалить категорию',
    ],

];
