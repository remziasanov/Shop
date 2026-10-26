<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
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

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->components->info(__('shop.command.created', ['email' => $email]));

        return self::SUCCESS;
    }
}
