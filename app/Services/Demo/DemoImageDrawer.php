<?php

declare(strict_types=1);

namespace App\Services\Demo;

use GdImage;
use RuntimeException;

/**
 * Draws fictional product pictures with GD (no network, no stock photos): a coloured background that is
 * deterministic per label, a few shapes and the label in Latin (built-in GD fonts have no Cyrillic).
 */
final class DemoImageDrawer
{
    /**
     * Draw a picture and return the path of a temporary JPEG (the caller removes it).
     */
    public function draw(string $sku, int $n, int $width = 1200, int $height = 900, int $quality = 85): string
    {
        $seed = crc32($sku);
        $rand = fn (int $k, int $max): int => crc32($sku.':'.$n.':'.$k) % (max(0, $max) + 1);
        $scale = $width / 1200;

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD is not available.');
        }

        $hue = ($seed + $n * 40) % 360;
        [$r, $g, $b] = self::hsl($hue, 0.45, 0.82);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, $r, $g, $b));

        for ($k = 0; $k < 5; $k++) {
            [$r, $g, $b] = self::hsl(($hue + 25 * ($k + 1)) % 360, 0.5, 0.55 - $k * 0.05);
            $color = (int) imagecolorallocate($image, $r, $g, $b);
            $x = (int) ($rand($k * 3, 1000) * $scale);
            $y = (int) ($rand($k * 3 + 1, 700) * $scale);
            $size = (int) ((120 + $rand($k * 3 + 2, 280)) * $scale);

            match ($k % 3) {
                0 => imagefilledellipse($image, $x + intdiv($size, 3), $y + intdiv($size, 3), $size, $size, $color),
                1 => imagefilledrectangle($image, $x, $y, $x + $size, $y + (int) ($size * 0.6), $color),
                default => imagefilledpolygon($image, [$x, $y + $size, $x + $size, $y + $size, $x + intdiv($size, 2), $y], $color),
            };
        }

        $this->drawLabel($image, $sku.' #'.$n, $width, $height);

        $path = tempnam(sys_get_temp_dir(), 'shopdemo');

        if ($path === false || ! imagejpeg($image, $path, $quality)) {
            throw new RuntimeException('Cannot write a temporary image.');
        }

        return $path;
    }

    /**
     * The label is drawn small with a built-in font and magnified, so it is readable.
     */
    private function drawLabel(GdImage $image, string $text, int $width, int $height): void
    {
        $scale = max(1, (int) round($width / 240));
        $textWidth = imagefontwidth(5) * strlen($text) + 16;
        $textHeight = imagefontheight(5) + 12;

        $label = imagecreatetruecolor($textWidth, $textHeight);

        if ($label === false) {
            return;
        }

        imagefilledrectangle($label, 0, 0, $textWidth, $textHeight, (int) imagecolorallocate($label, 255, 255, 255));
        imagestring($label, 5, 8, 6, $text, (int) imagecolorallocate($label, 40, 40, 40));

        $w = $textWidth * $scale;
        $h = $textHeight * $scale;
        imagecopyresized($image, $label, intdiv($width - $w, 2), intdiv($height - $h, 2), 0, 0, $w, $h, $textWidth, $textHeight);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hsl(int $h, float $s, float $l): array
    {
        $l = max(0.1, min(0.95, $l));
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (intdiv($h, 60) % 6) {
            0 => [$c, $x, 0],
            1 => [$x, $c, 0],
            2 => [0, $c, $x],
            3 => [0, $x, $c],
            4 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }
}
