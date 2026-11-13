<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Catalog\SlugService;
use Carbon\CarbonInterface;
use Database\Factories\OptionValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A value of an option: «красный», «M» (VR-01). Unique inside the option by slug.
 *
 * @property int $id
 * @property int $option_type_id
 * @property string $value
 * @property string $slug
 * @property string|null $hex
 * @property string|null $image_path
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read OptionType $optionType
 */
class OptionValue extends Model
{
    /** @use HasFactory<OptionValueFactory> */
    use HasFactory;

    protected $fillable = ['option_type_id', 'value', 'slug', 'hex', 'image_path', 'sort_order'];

    protected static function booted(): void
    {
        static::saving(function (OptionValue $value): void {
            if (! $value->exists || $value->isDirty(['slug', 'value', 'option_type_id']) || trim((string) $value->slug) === '') {
                $value->slug = self::uniqueSlug($value);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<OptionType, $this>
     */
    public function optionType(): BelongsTo
    {
        return $this->belongsTo(OptionType::class);
    }

    /**
     * Slug from the manual slug or the text, unique inside the option ("-2", "-3" on a collision).
     */
    private static function uniqueSlug(OptionValue $value): string
    {
        $slugs = app(SlugService::class);
        $base = $slugs->normalize(trim((string) $value->slug) !== '' ? (string) $value->slug : (string) $value->value);

        if ($base === '') {
            $base = 'value';
        }

        $taken = DB::table('option_values')
            ->where('option_type_id', $value->option_type_id)
            ->when($value->exists, fn ($query) => $query->where('id', '!=', $value->id))
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->pluck('slug')
            ->flip();

        $candidate = $base;

        for ($suffix = 2; isset($taken[$candidate]); $suffix++) {
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }
}
