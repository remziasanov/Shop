<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\System\CheckResult;
use App\Services\System\EnvironmentChecker;
use Illuminate\Support\Facades\DB;

/**
 * Stage 15: the checks of `shop:doctor` that version 2.0 added or that decide whether a release can run (D-079, RB-14,
 * VR-04, FL-04).
 */
function v2Check(string $key): CheckResult
{
    return collect(app(EnvironmentChecker::class)->run())->firstWhere('key', $key);
}

it('reports the PDF library: extensions, writable font cache and the installed version (D-079, CX-12)', function (): void {
    $result = v2Check('dompdf');

    expect($result->status)->toBe(CheckStatus::Ok)
        ->and($result->value)->toStartWith('dompdf ')
        ->and($result->label)->toContain('dompdf')
        ->and(v2Check('ext_dom')->status)->toBe(CheckStatus::Ok);
});

it('fails the PDF check when the font cache directory is missing or not writable', function (): void {
    config(['dompdf.options.font_cache' => storage_path('framework/testing/no-such-fonts-'.uniqid())]);

    $result = v2Check('dompdf');

    expect($result->status)->toBe(CheckStatus::Error)
        ->and($result->hint)->toContain('chmod 775')->toContain('no-such-fonts');
});

it('fails with a hint when an extension of the PDF library is missing', function (): void {
    // The check names the missing extensions; here the real extension list is the host's, so only the hint text is checked.
    expect(__('system.hints.dompdf_extensions', ['names' => 'dom, gd']))->toContain('mbstring')->toContain('dom, gd');
});

it('reports an article used by a product and a variant (VR-04, D-087)', function (): void {
    expect(v2Check('variant_skus')->status)->toBe(CheckStatus::Ok);

    Product::factory()->create(['sku' => 'DUP-1']);
    DB::table('product_variants')->insert([
        'product_id' => variantCard()->id, 'sku' => 'DUP-1', 'combo_hash' => sha1('x'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = v2Check('variant_skus');

    expect($result->status)->toBe(CheckStatus::Error)->and($result->hint)->toContain('DUP-1');
});

it('needs an active administrator and a role for every user (RB-14)', function (): void {
    $admin = User::factory()->admin()->create();
    expect(v2Check('staff_roles')->status)->toBe(CheckStatus::Ok);

    $admin->forceFill(['is_active' => false])->save();
    expect(v2Check('staff_roles')->status)->toBe(CheckStatus::Error);

    $admin->forceFill(['is_active' => true])->save();
    User::factory()->create(['role_id' => null]);
    expect(v2Check('staff_roles')->status)->toBe(CheckStatus::Error);
});

it('warns while the characteristics are still in JSON and is ok after the migration (FL-04)', function (): void {
    expect(v2Check('attribute_migration')->status)->toBe(CheckStatus::Ok);

    Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Белый']]]);
    expect(v2Check('attribute_migration')->status)->toBe(CheckStatus::Warning);

    $this->artisan('shop:attributes-migrate')->assertSuccessful();
    expect(v2Check('attribute_migration')->status)->toBe(CheckStatus::Ok);
});

it('prints the new checks and fails the command only on an error (shop:doctor)', function (): void {
    User::factory()->admin()->create();
    Artisan::call('shop:doctor', ['--json' => true]);
    $keys = array_column(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR), 'key');

    expect($keys)->toContain('dompdf', 'variant_skus', 'staff_roles', 'attribute_migration', 'ext_dom');

    config(['dompdf.options.font_cache' => '/nonexistent/fonts']);
    $this->artisan('shop:doctor')->assertFailed();
});
