<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SystemRole;
use App\Services\Users\RoleService;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Staff role (RB-01). Permissions live in `role_permissions`; the Administrator role stores none and allows
 * everything (RB-02). Change permissions only through {@see RoleService}.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property int $permissions_version
 * @property int|null $users_count
 * @property int|null $permissions_count
 */
// `is_system` and `permissions_version` are never mass assigned: the service sets them with forceFill().
#[Fillable(['code', 'name', 'description'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'permissions_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isAdministrator(): bool
    {
        return $this->code === SystemRole::Admin->value;
    }

    /**
     * Granted permission keys, straight from the database (the cached read path is the PermissionService).
     *
     * @return list<string>
     */
    public function storedPermissions(): array
    {
        return DB::table('role_permissions')
            ->where('role_id', $this->getKey())
            ->orderBy('permission')
            ->pluck('permission')
            ->map(fn (mixed $permission): string => (string) $permission)
            ->all();
    }
}
