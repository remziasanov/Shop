<?php

declare(strict_types=1);

namespace App\Services\Home;

/**
 * The built-in icon set of the «Преимущества» block (5.2): 12 inline SVG icons (24x24, stroke).
 */
final class HomeIcons
{
    /** @var array<string, string> icon key => SVG inner markup (trusted constants, never user input) */
    private const PATHS = [
        'truck' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'gift' => '<rect x="3" y="9" width="18" height="4"/><path d="M5 13v7h14v-7M12 9v11"/><path d="M12 9C10 5 6 6 7 9c.5 1.2 3 1 5 0zM12 9c2-4 6-3 5 0-.5 1.2-3 1-5 0z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>',
        'star' => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'box' => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
        'return' => '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 010 10h-3"/>',
        'percent' => '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2"/><circle cx="17" cy="17" r="2"/>',
        'map-pin' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0114 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4z"/>',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::PATHS);
    }

    /**
     * @return array<string, string> icon key => localized name
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::keys() as $key) {
            $options[$key] = __('home.icons.'.$key);
        }

        return $options;
    }

    /**
     * Inline SVG of an icon; unknown keys fall back to a star.
     */
    public static function svg(?string $key, string $class = 'h-8 w-8'): string
    {
        $inner = self::PATHS[$key ?? ''] ?? self::PATHS['star'];

        return '<svg class="'.e($class).'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$inner.'</svg>';
    }
}
