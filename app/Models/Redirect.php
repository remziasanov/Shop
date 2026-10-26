<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Old path to new path (SF-80, D-018). Managed by App\Services\Catalog\RedirectService.
 *
 * @property int $id
 * @property string $from_path
 * @property string $to_path
 */
class Redirect extends Model
{
    protected $fillable = ['from_path', 'to_path'];
}
