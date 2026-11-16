<?php

declare(strict_types=1);

namespace App\Filament\Avatars;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Hardening S3-4 (D-204): admin avatars are drawn locally (an SVG data: URI with the initials) instead of
 * Filament's default ui-avatars.com, which would receive the staff member's name on every admin page.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = $this->initials((string) Filament::getNameForDefaultAvatar($record));
        $background = Color::convertToHex(FilamentColor::getColor('primary')[600] ?? Color::Blue[600]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
            .'<rect width="64" height="64" fill="'.e($background).'"/>'
            .'<text x="32" y="32" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="26" font-weight="600"'
            .' text-anchor="middle" dominant-baseline="central">'.e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Up to two initials; leading punctuation of a word is skipped.
     */
    private function initials(string $name): string
    {
        $letters = [];

        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word);

            if ($word !== '') {
                $letters[] = mb_strtoupper(mb_substr($word, 0, 1));
            }

            if (count($letters) === 2) {
                break;
            }
        }

        return implode('', $letters);
    }
}
