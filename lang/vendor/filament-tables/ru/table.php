<?php

declare(strict_types=1);

// Keys missing from Filament's bundled Russian translations (mostly screen-reader texts).
// Laravel merges these with the package file.
return [
    'column_manager' => [
        'position' => 'Позиция :position из :count.',
        'position_in_group' => 'Позиция :position из :count в группе :group.',
        'reordered' => ':column перемещена на позицию :position из :count.',
        'reordered_deferred' => ':column перемещена на позицию :position из :count. Примените колонки, чтобы сохранить изменения.',
        'reordered_in_group' => ':column перемещена на позицию :position из :count в группе :group.',
        'reordered_in_group_deferred' => ':column перемещена на позицию :position из :count в группе :group. Примените колонки, чтобы сохранить изменения.',
        'actions' => [
            'move_up' => [
                'label' => 'Переместить :column выше',
            ],
            'move_down' => [
                'label' => 'Переместить :column ниже',
            ],
            'reorder' => [
                'label' => 'Изменить порядок колонки',
            ],
        ],
    ],
    'columns' => [
        'editable' => [
            'saving' => 'Сохранение…',
            'saved' => 'Изменение сохранено.',
            'failure' => 'Не удалось подтвердить сохранение. Обновите страницу и попробуйте снова.',
            'unavailable' => 'Эту запись больше нельзя изменить.',
        ],
        'icon' => [
            'boolean' => [
                'true' => 'Да',
                'false' => 'Нет',
            ],
        ],
    ],
    'actions' => [
        'reorder_record' => [
            'label' => 'Изменить порядок записи :key',
        ],
        'move_record_up' => [
            'label' => 'Переместить :label выше',
        ],
        'move_record_down' => [
            'label' => 'Переместить :label ниже',
        ],
        'toggle_record_content' => [
            'label' => 'Развернуть/свернуть запись :key',
        ],
    ],
    'loading' => 'Загрузка…',
    'reordering' => [
        'position' => ':label, позиция :position из :count.',
        'moved' => ':label перемещена на позицию :position из :count.',
    ],
    'result_count' => '{0} Нет записей|{1} :count запись|[2,4] :count записи|[5,*] :count записей',
];
