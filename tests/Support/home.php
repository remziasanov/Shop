<?php

declare(strict_types=1);

use App\Settings\HomePageSettings;

/**
 * @param  list<array<string, mixed>>  $blocks
 */
function storeHomeBlocks(array $blocks): void
{
    $settings = app(HomePageSettings::class);
    $settings->blocks = $blocks;
    $settings->save();
}

function homeBlock(string $type, array $data = []): array
{
    return ['type' => $type, 'data' => $data + ['visible' => true]];
}

function savedHomeBlocks(): array
{
    return array_values(app(HomePageSettings::class)->refresh()->blocks);
}

function bannerImage(string $name = 'a'): array
{
    return ['large' => "home/{$name}-1600.webp", 'small' => "home/{$name}-800.webp", 'width' => 1600, 'height' => 600];
}

/**
 * The page content between <main> tags (the header and footer menus list categories too).
 */
function homeMain(\Illuminate\Testing\TestResponse $response): string
{
    preg_match('~<main[^>]*>(.*?)</main>~s', $response->getContent(), $match);

    return $match[1] ?? '';
}
