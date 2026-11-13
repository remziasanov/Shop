<?php

declare(strict_types=1);

// Keys missing from Filament's bundled Russian translations (mostly screen-reader texts).
// Laravel merges these with the package file.
return [
    'builder' => [
        'reordering' => [
            'item' => 'Блок',
            'position' => ':label, позиция :position из :count',
            'moved' => ':label перемещён на позицию :position из :count',
        ],
        'block_picker' => [
            'no_search_results_message' => 'Блоки не найдены.',
            'search_prompt' => 'Поиск блоков',
        ],
    ],
    'checkbox_list' => [
        'required_description' => 'Выберите хотя бы один вариант.',
    ],
    'color_picker' => [
        'panel_label' => 'Выбор цвета',
    ],
    'date_time_picker' => [
        'month_select' => [
            'label' => 'Месяц',
        ],
        'year_input' => [
            'label' => 'Год',
        ],
        'hour_input' => [
            'label' => 'Час',
        ],
        'minute_input' => [
            'label' => 'Минута',
        ],
        'second_input' => [
            'label' => 'Секунда',
        ],
    ],
    'file_upload' => [
        'placeholder' => 'Файлы не загружены',
        'actions' => [
            'download' => [
                'label' => 'Скачать',
            ],
            'open' => [
                'label' => 'Открыть в новой вкладке',
            ],
        ],
        'editor' => [
            'label' => 'Редактор изображения',
        ],
    ],
    'key_value' => [
        'actions' => [
            'move_down' => [
                'label' => 'Ниже',
            ],
            'move_up' => [
                'label' => 'Выше',
            ],
        ],
        'columns' => [
            'actions' => [
                'label' => 'Действия',
            ],
            'reorder' => [
                'label' => 'Порядок',
            ],
        ],
        'reordering' => [
            'row' => 'Строка',
            'position' => ':label, позиция :position из :count',
            'moved' => ':label перемещена на позицию :position из :count',
        ],
    ],
    'repeater' => [
        'reordering' => [
            'item' => 'Элемент',
            'position' => ':label, позиция :position из :count',
            'moved' => ':label перемещён на позицию :position из :count',
        ],
        'columns' => [
            'actions' => [
                'label' => 'Действия',
            ],
            'reorder' => [
                'label' => 'Порядок',
            ],
        ],
    ],
    'rich_editor' => [
        'actions' => [
            'close_panel' => [
                'label' => 'Закрыть панель',
            ],
        ],
        'custom_blocks' => [
            'actions' => [
                'delete' => [
                    'label' => 'Удалить блок',
                ],
                'edit' => [
                    'label' => 'Изменить блок',
                ],
            ],
            'no_search_results_message' => 'Блоки не найдены.',
            'search_label' => 'Поиск блоков',
            'search_prompt' => 'Поиск блоков',
        ],
        'toolbar' => [
            'label' => 'Панель инструментов редактора',
        ],
    ],
    'select' => [
        'actions' => [
            'clear' => [
                'label' => 'Очистить выбор',
            ],
            'remove_option' => [
                'label' => 'Убрать :label',
            ],
        ],
        'error_message' => 'Не удалось загрузить варианты. Попробуйте ещё раз.',
        'required_description' => 'Выберите вариант.',
        'search_label' => 'Поиск',
    ],
    'tags_input' => [
        'tag_added' => 'Добавлено: :tag',
        'tag_removed' => 'Удалено: :tag',
    ],
    'toggle_buttons' => [
        'required_description' => 'Выберите хотя бы один вариант.',
    ],
];
