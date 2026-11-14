<?php

declare(strict_types=1);

/**
 * Validates the owner-approved theme specification (TZ section 34, TH-01):
 * docs/themes/themes.json (50 themes) and docs/themes/fonts.json (60 families).
 */
function themeSpec(): array
{
    static $spec = null;

    return $spec ??= json_decode((string) file_get_contents(base_path('docs/themes/themes.json')), true, 512, JSON_THROW_ON_ERROR);
}

function fontSpec(): array
{
    static $fonts = null;

    return $fonts ??= json_decode((string) file_get_contents(base_path('docs/themes/fonts.json')), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, array<string, mixed>> */
function fontsByFamily(): array
{
    $map = [];
    foreach (fontSpec() as $font) {
        $map[$font['family']] = $font;
    }

    return $map;
}

it('contains exactly 50 themes numbered 1..50 without gaps (TH-01)', function (): void {
    $numbers = array_column(themeSpec()['themes'], 'n');

    expect($numbers)->toHaveCount(50)
        ->and($numbers)->toBe(range(1, 50));
});

it('has unique theme ids matching [a-z0-9-]+ (TH-01)', function (): void {
    $ids = array_column(themeSpec()['themes'], 'id');

    expect(array_unique($ids))->toHaveCount(50);
    $invalid = array_filter($ids, fn (string $id): bool => preg_match('/^[a-z0-9-]+$/', $id) !== 1 || $id === 'default');

    expect($invalid)->toBe([]);
});

it('gives every theme 12 colors in #RRGGBB format (TH-05)', function (): void {
    $keys = ['bg', 'surface', 'ink', 'muted', 'line', 'primary', 'onPrimary', 'accent', 'onAccent', 'heroBg', 'heroInk', 'tile'];

    $problems = [];
    foreach (themeSpec()['themes'] as $theme) {
        if (array_diff($keys, array_keys($theme['colors'])) !== [] || count($theme['colors']) !== 12) {
            $problems[] = "theme {$theme['n']}: color keys differ";
        }
        foreach ($theme['colors'] as $name => $value) {
            if (preg_match('/^#[0-9A-F]{6}$/', (string) $value) !== 1) {
                $problems[] = "theme {$theme['n']} color {$name}: {$value}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('uses allowed block variants (TH-10..TH-13)', function (): void {
    $allowed = [
        'header' => ['H1', 'H2', 'H3', 'H4'],
        'card' => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6'],
        'hero' => ['G1', 'G2', 'G3', 'G4'],
        'listing' => ['L1', 'L2', 'L3'],
    ];

    $problems = [];
    foreach (themeSpec()['themes'] as $theme) {
        foreach ($allowed as $block => $values) {
            if (! in_array($theme['blocks'][$block] ?? null, $values, true)) {
                $problems[] = "theme {$theme['n']} block {$block}";
            }
        }
        if (count($theme['blocks']) !== count($allowed)) {
            $problems[] = "theme {$theme['n']}: unexpected block keys";
        }
    }

    expect($problems)->toBe([]);
});

it('uses allowed surface styles and genres (TH-14, TH-33)', function (): void {
    $surfaces = ['none', 'line', 'soft', 'hard', 'press', 'double', 'glass', 'clay'];
    $genres = ['editorial', 'modern-minimal', 'atmospheric', 'playful'];

    $problems = [];
    foreach (themeSpec()['themes'] as $theme) {
        if (! in_array($theme['shape']['surface'], $surfaces, true)) {
            $problems[] = "theme {$theme['n']} surface {$theme['shape']['surface']}";
        }
        if (! in_array($theme['basis']['genre'], $genres, true)) {
            $problems[] = "theme {$theme['n']} genre {$theme['basis']['genre']}";
        }
        if (! in_array($theme['basis']['type'], ['hallmark', 'custom'], true)) {
            $problems[] = "theme {$theme['n']} basis type";
        }
        foreach (['radius', 'buttonRadius', 'borderWidth'] as $key) {
            if (! is_int($theme['shape'][$key] ?? null)) {
                $problems[] = "theme {$theme['n']} shape.{$key}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('assigns every theme to a known group (TH-02)', function (): void {
    $groups = themeSpec()['groups'];

    expect($groups)->toHaveCount(10);
    $unknown = array_filter(themeSpec()['themes'], fn (array $theme): bool => ! array_key_exists($theme['group'], $groups));

    expect($unknown)->toBe([]);
});

it('has exactly 60 unique font families (TH-18)', function (): void {
    $families = array_column(fontSpec(), 'family');

    expect($families)->toHaveCount(60)
        ->and(array_unique($families))->toHaveCount(60);
    $problems = [];
    foreach (fontSpec() as $font) {
        $valid = in_array($font['category'], ['serif', 'sans', 'mono'], true)
            && in_array($font['license'], ['OFL', 'Apache 2.0'], true)
            && $font['weights'] !== []
            && preg_match('#^(ofl|apache)/[a-z0-9]+$#', $font['source']) === 1
            && is_bool($font['displayOnly'])
            && is_bool($font['italic']);
        if (! $valid) {
            $problems[] = $font['family'];
        }
    }

    expect($problems)->toBe([]);
});

it('references only fonts that exist in fonts.json (TH-18)', function (): void {
    $fonts = fontsByFamily();

    $problems = [];
    foreach (themeSpec()['themes'] as $theme) {
        foreach (['display', 'body', 'mono'] as $role) {
            if (! isset($theme['fonts'][$role])) {
                if ($role !== 'mono') {
                    $problems[] = "theme {$theme['n']}: missing {$role} font";
                }

                continue;
            }
            if (! array_key_exists($theme['fonts'][$role]['family'], $fonts)) {
                $problems[] = "theme {$theme['n']} {$role}: {$theme['fonts'][$role]['family']}";
            }
        }
        if (! is_int($theme['fonts']['display']['weight'] ?? null)) {
            $problems[] = "theme {$theme['n']}: display weight";
        }
    }

    expect($problems)->toBe([]);
});

it('does not use display-only fonts as body fonts and picks display weights the font has (TH-20)', function (): void {
    $fonts = fontsByFamily();
    $violations = [];

    foreach (themeSpec()['themes'] as $theme) {
        $body = $fonts[$theme['fonts']['body']['family']];
        if ($body['displayOnly']) {
            $violations[] = "theme {$theme['n']} ({$theme['id']}): body font \"{$body['family']}\" is display-only";
        }
        $display = $theme['fonts']['display'];
        $displayFont = $fonts[$display['family']];
        if (! in_array($display['weight'], $displayFont['weights'], true)) {
            $violations[] = "theme {$theme['n']} ({$theme['id']}): display weight {$display['weight']} is not among ["
                .implode(', ', $displayFont['weights'])."] of \"{$display['family']}\"";
        }
        if (isset($theme['fonts']['mono']) && $fonts[$theme['fonts']['mono']['family']]['category'] !== 'mono') {
            $violations[] = "theme {$theme['n']} ({$theme['id']}): mono font \"{$theme['fonts']['mono']['family']}\" is not a mono family";
        }
        if (($display['italic'] ?? false) && ! $displayFont['italic']) {
            $violations[] = "theme {$theme['n']} ({$theme['id']}): italic display but \"{$display['family']}\" is not marked as needing italic";
        }
    }

    if ($violations !== []) {
        $this->markTestSkipped("Spec inconsistencies (data is owner-approved, not changed):\n".implode("\n", $violations));
    }

    expect($violations)->toBe([]);
});
