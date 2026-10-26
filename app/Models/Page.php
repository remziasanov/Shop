<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PageObserver;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Static page /page/{slug} (SF-70, AD-60). Content is sanitized on save by the observer.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $content
 * @property bool $is_active
 * @property string|null $meta_title
 * @property string|null $meta_description
 */
#[ObservedBy(PageObserver::class)]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'is_active',
        'meta_title',
        'meta_description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    public function url(): string
    {
        return route('page.show', $this->slug);
    }
}
