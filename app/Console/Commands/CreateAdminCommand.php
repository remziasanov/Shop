<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('shop:create-admin {--name= : Administrator name} {--email= : Administrator email} {--password= : Password (at least 10 characters)}')]
#[Description('Create an administrator user (AD-02)')]
class CreateAdminCommand extends Command
{
    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        if ($interactive) {
            $name = filled($name) ? $name : text(__('shop.command.name_prompt'), required: true);
            $email = filled($email) ? $email : text(__('shop.command.email_prompt'), required: true);
            $password = filled($password) ? $password : password(__('shop.command.password_prompt'), required: true);
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::min(10)],
            ],
            [],
            [
                'name' => __('shop.users.fields.name'),
                'email' => __('shop.users.fields.email'),
                'password' => __('shop.users.fields.password'),
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $adminRoleId = Role::query()->where('code', SystemRole::Admin->value)->value('id');

        if ($adminRoleId === null) {
            $this->components->error(__('roles.errors.no_admin_role'));

            return self::FAILURE;
        }

        // RB-14: the account gets the system role «Администратор».
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role_id' => (int) $adminRoleId,
            'is_active' => true,
        ])->save();

        // RB-12: the first administrator is created outside the panel, so there is no actor; ids and the role only.
        app(AuditLogger::class)->record(AuditAction::UserCreated, null, $user, ['role' => SystemRole::Admin->value, 'active' => true, 'source' => 'console']);

        $this->components->info(__('shop.command.created', ['email' => $email]));

        return self::SUCCESS;
    }
}
