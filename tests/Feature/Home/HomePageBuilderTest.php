<?php

declare(strict_types=1);

use App\Filament\Pages\ManageHomePage;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/home.php';

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('lets admin and manager open the page and keeps it away from inactive staff', function () {
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ManageHomePage::class)->assertSuccessful();

    $this->actingAs(User::factory()->manager()->create());
    Livewire::test(ManageHomePage::class)->assertSuccessful();

    $this->actingAs(User::factory()->manager()->inactive()->create());
    expect(ManageHomePage::canAccess())->toBeFalse();
});

it('is in the content navigation group with the label «Главная»', function () {
    expect(ManageHomePage::getNavigationLabel())->toBe('Главная')
        ->and((string) ManageHomePage::getNavigationGroup())->toBe('Контент');
});

it('has a preview action that opens the home page in a new tab', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->assertActionExists('preview', fn ($action): bool => $action->getUrl() === route('home') && $action->shouldOpenUrlInNewTab());
});

it('saves every block type as admin', function () {
    $this->actingAs(User::factory()->admin()->create());
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create();

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [
            homeBlock('banner', [
                'title' => 'Весна',
                'subtitle' => 'Скидки',
                'image_upload' => [UploadedFile::fake()->image('desktop.jpg', 2000, 800)],
                'image_mobile_upload' => [UploadedFile::fake()->image('mobile.jpg', 900, 900)],
                'button_text' => 'Смотреть',
                'button_link' => '/catalog',
            ]),
            homeBlock('slider', [
                'autoplay' => true,
                'interval' => 6,
                'slides' => [['title' => 'Один', 'image_upload' => [UploadedFile::fake()->image('s1.png', 1800, 600)], 'button_link' => 'https://example.com']],
            ]),
            homeBlock('categories', ['title' => 'Категории', 'mode' => 'selected', 'categories' => [$category->id], 'max_count' => 6]),
            homeBlock('products', ['title' => 'Хиты', 'source' => 'manual', 'product_ids' => [$product->id], 'limit' => 4, 'sort' => 'default', 'show_all' => true]),
            homeBlock('text', ['title' => 'О нас', 'content' => '<p>Текст</p>']),
            homeBlock('advantages', ['items' => [['icon' => 'truck', 'title' => 'Доставка', 'text' => 'Быстро']]]),
            homeBlock('html', ['html' => '<div id="raw">x</div>']),
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $blocks = savedHomeBlocks();

    expect(array_column($blocks, 'type'))->toBe(['banner', 'slider', 'categories', 'products', 'text', 'advantages', 'html'])
        ->and($blocks[0]['data']['image']['large'])->toStartWith('home/')
        ->and($blocks[0]['data']['image_mobile']['small'])->toStartWith('home/')
        ->and($blocks[1]['data']['slides'][0]['image']['large'])->toStartWith('home/')
        ->and($blocks[6]['data']['html'])->toBe('<div id="raw">x</div>');
});

it('processes banner images to public webp variants without keeping the upload', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', ['image_upload' => [UploadedFile::fake()->image('big.jpg', 2400, 1000)]])]])
        ->call('save')
        ->assertHasNoFormErrors();

    $image = savedHomeBlocks()[0]['data']['image'];

    expect($image['large'])->toEndWith('-1600.webp')
        ->and($image['small'])->toEndWith('-800.webp')
        ->and($image['width'])->toBe(1600)
        ->and($image['height'])->toBe(667);
    Storage::disk('public')->assertExists([$image['large'], $image['small']]);
    expect(Storage::disk('local')->allFiles('tmp/home-images'))->toBe([]);

    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($image['small']));
    expect([$w, $h])->toBe([800, 334]);
});

it('requires the desktop image of a banner', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', ['title' => 'Без картинки'])]])
        ->call('save')
        ->assertHasFormErrors();
});

it('rejects svg uploads', function () {
    $this->actingAs(User::factory()->admin()->create());
    $svg = [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')];

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', ['image_upload' => $svg])]])
        ->call('save')
        ->assertHasFormErrors();

    expect(savedHomeBlocks())->toBe([]);
});

it('rejects files bigger than 10 MB', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', ['image_upload' => [UploadedFile::fake()->image('huge.jpg', 100, 100)->size(11 * 1024)]])]])
        ->call('save')
        ->assertHasFormErrors();
});

it('rejects unsafe button links', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', [
            'image_upload' => [UploadedFile::fake()->image('a.jpg', 1200, 400)],
            'button_link' => 'javascript:alert(1)',
        ])]])
        ->call('save')
        ->assertHasFormErrors();
});

it('deletes replaced, removed and orphaned images', function () {
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [
            homeBlock('banner', [
                'image_upload' => [UploadedFile::fake()->image('a.jpg', 1800, 600)],
                'image_mobile_upload' => [UploadedFile::fake()->image('m.jpg', 800, 800)],
            ]),
            homeBlock('banner', ['image_upload' => [UploadedFile::fake()->image('b.jpg', 1800, 600)]]),
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $first = savedHomeBlocks();
    $files = fn (array $image): array => [$image['large'], $image['small']];
    Storage::disk('public')->assertExists([...$files($first[0]['data']['image']), ...$files($first[0]['data']['image_mobile']), ...$files($first[1]['data']['image'])]);

    // Replace the first desktop image, remove the mobile one and delete the second block.
    $page->fillForm(['blocks' => [homeBlock('banner', [
        'image' => $first[0]['data']['image'],
        'image_mobile' => $first[0]['data']['image_mobile'],
        'image_upload' => [UploadedFile::fake()->image('new.jpg', 1700, 500)],
        'image_mobile_remove' => true,
    ])]])
        ->call('save')
        ->assertHasNoFormErrors();

    $second = savedHomeBlocks();

    expect($second)->toHaveCount(1)
        ->and($second[0]['data']['image_mobile'])->toBeNull()
        ->and($second[0]['data']['image']['large'])->not->toBe($first[0]['data']['image']['large']);

    Storage::disk('public')->assertExists($files($second[0]['data']['image']));
    Storage::disk('public')->assertMissing([
        ...$files($first[0]['data']['image']),
        ...$files($first[0]['data']['image_mobile']),
        ...$files($first[1]['data']['image']),
    ]);
});

it('never deletes or adopts files it did not create when image references are tampered with', function () {
    $this->actingAs(User::factory()->admin()->create());
    Storage::disk('public')->put('products/1/precious-1600.webp', 'x');

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', [
            'image' => ['large' => 'products/1/precious-1600.webp', 'small' => 'products/1/precious-1600.webp', 'width' => 10, 'height' => 10],
            'image_upload' => [UploadedFile::fake()->image('a.jpg', 1200, 400)],
        ])]])
        ->call('save');

    Storage::disk('public')->assertExists('products/1/precious-1600.webp');

    // A reference to a foreign file without an upload is dropped, not stored.
    storeHomeBlocks([]);
    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('slider', ['interval' => 5, 'slides' => [[
            'image' => ['large' => 'products/1/precious-1600.webp', 'small' => 'products/1/precious-1600.webp', 'width' => 10, 'height' => 10],
            'image_upload' => [UploadedFile::fake()->image('a.jpg', 1200, 400)],
        ]]])]])
        ->call('save');

    Storage::disk('public')->assertExists('products/1/precious-1600.webp');
});

it('does not offer the html block to managers and keeps stored html blocks in place on save', function () {
    storeHomeBlocks([
        homeBlock('text', ['title' => 'Первый', 'content' => '<p>a</p>']),
        homeBlock('html', ['html' => '<b id="secret">raw</b>']),
        homeBlock('text', ['title' => 'Последний', 'content' => '<p>b</p>']),
    ]);
    $this->actingAs(User::factory()->manager()->create());

    $page = Livewire::test(ManageHomePage::class);
    $page->assertDontSee('secret');

    $titles = collect($page->get('data.blocks'))->pluck('data.title')->values()->all();
    expect($titles)->toBe(['Первый', 'Последний']);

    $keys = array_keys($page->get('data.blocks'));
    $page->set("data.blocks.{$keys[0]}.data.title", 'Первый (правка)')->call('save')->assertHasNoFormErrors();

    $blocks = savedHomeBlocks();
    expect(array_column($blocks, 'type'))->toBe(['text', 'html', 'text'])
        ->and($blocks[0]['data']['title'])->toBe('Первый (правка)')
        ->and($blocks[1]['data']['html'])->toBe('<b id="secret">raw</b>');
});

it('ignores html blocks submitted by a manager', function () {
    storeHomeBlocks([homeBlock('html', ['html' => '<i>old</i>'])]);
    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('html', ['html' => '<script>alert(1)</script>'])]])
        ->call('save');

    $blocks = savedHomeBlocks();
    expect($blocks)->toHaveCount(1)->and($blocks[0]['data']['html'])->toBe('<i>old</i>');
});

it('sanitizes the rich text of the text block when saving', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('text', ['content' => '<p>ok</p><script>alert(1)</script>'])]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(savedHomeBlocks()[0]['data']['content'])->not->toContain('script')->toContain('ok');
});

it('stores the visibility toggle and the order of blocks', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [
            homeBlock('text', ['title' => 'B', 'content' => '<p>b</p>', 'visible' => false]),
            homeBlock('text', ['title' => 'A', 'content' => '<p>a</p>']),
        ]])
        ->call('save');

    $blocks = savedHomeBlocks();
    expect($blocks[0]['data']['title'])->toBe('B')
        ->and($blocks[0]['data']['visible'])->toBeFalse()
        ->and($blocks[1]['data']['visible'])->toBeTrue();
});

it('never upscales small banner images', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', ['image_upload' => [UploadedFile::fake()->image('small.png', 500, 200)]])]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(savedHomeBlocks()[0]['data']['image'])->toMatchArray(['width' => 500, 'height' => 200]);
});

it('can be saved twice in a row without losing processed images', function () {
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [homeBlock('banner', ['image_upload' => [UploadedFile::fake()->image('a.jpg', 1700, 600)]])]])
        ->call('save')
        ->assertHasNoFormErrors();

    $image = savedHomeBlocks()[0]['data']['image'];

    $page->call('save')->assertHasNoFormErrors();

    expect(savedHomeBlocks()[0]['data']['image'])->toBe($image);
    Storage::disk('public')->assertExists([$image['large'], $image['small']]);
});
