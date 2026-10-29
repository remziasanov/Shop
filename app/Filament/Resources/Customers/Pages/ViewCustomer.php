<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Exceptions\CustomerAccountException;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\CustomerThrottledException;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Customers\CustomerAdminService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Customer card with the actions of CA-21: change group, block / unblock with a reason, anonymize at once, resend the
 * confirmation code. Every action is hidden without the permission and checked again by the service; there is no
 * «войти под покупателем».
 *
 * @property Customer $record
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Customer $customer */
        $customer = parent::resolveRecord($key);

        return $customer->load('company');
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            $this->changeGroupAction(),
            $this->blockAction(),
            $this->unblockAction(),
            $this->resendCodeAction(),
            $this->anonymizeAction(),
        ];
    }

    private function changeGroupAction(): Action
    {
        return Action::make('change_group')
            ->label(__('customers.actions.change_group'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->visible(fn (): bool => Gate::allows('changeGroup', $this->record))
            ->schema([
                Select::make('group_id')
                    ->label(__('customers.actions.group'))
                    ->options(fn (): array => CustomersTable::assignableGroups())
                    ->default(fn (): int => $this->record->customer_group_id)
                    ->required()
                    ->native(false),
            ])
            ->action(fn (array $data) => $this->run(function (CustomerAdminService $service, User $actor) use ($data): void {
                $group = CustomerGroup::query()->findOrFail((int) $data['group_id']);
                $service->changeGroup($this->record, $group, $actor);
                Notification::make()->success()->title(__('customers.notifications.group_changed'))->send();
            }));
    }

    private function blockAction(): Action
    {
        return Action::make('block')
            ->label(__('customers.actions.block'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->visible(fn (): bool => Gate::allows('block', $this->record) && ! $this->record->is_blocked)
            ->requiresConfirmation()
            ->modalHeading(__('customers.actions.block_heading'))
            ->modalDescription(__('customers.actions.block_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('customers.actions.block_reason'))
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(fn (array $data) => $this->run(function (CustomerAdminService $service, User $actor) use ($data): void {
                $service->block($this->record, is_string($data['reason'] ?? null) ? $data['reason'] : null, $actor);
                Notification::make()->success()->title(__('customers.notifications.blocked'))->send();
            }));
    }

    private function unblockAction(): Action
    {
        return Action::make('unblock')
            ->label(__('customers.actions.unblock'))
            ->icon(Heroicon::OutlinedLockOpen)
            ->visible(fn (): bool => Gate::allows('block', $this->record) && $this->record->is_blocked)
            ->requiresConfirmation()
            ->action(fn () => $this->run(function (CustomerAdminService $service, User $actor): void {
                $service->unblock($this->record, $actor);
                Notification::make()->success()->title(__('customers.notifications.unblocked'))->send();
            }));
    }

    private function resendCodeAction(): Action
    {
        return Action::make('resend_code')
            ->label(__('customers.actions.resend_code'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('update', $this->record) && $this->record->email_verified_at === null)
            ->requiresConfirmation()
            ->modalDescription(__('customers.actions.resend_description'))
            ->action(fn () => $this->run(function (CustomerAdminService $service, User $actor): void {
                $service->resendConfirmationCode($this->record, $actor, (string) request()->ip());
                Notification::make()->success()->title(__('customers.notifications.code_sent'))->send();
            }));
    }

    private function anonymizeAction(): Action
    {
        return Action::make('anonymize')
            ->label(__('customers.actions.anonymize'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->visible(fn (): bool => Gate::allows('anonymize', $this->record))
            ->requiresConfirmation()
            ->modalHeading(__('customers.actions.anonymize_heading'))
            ->modalDescription(__('customers.actions.anonymize_description'))
            ->action(fn () => $this->run(function (CustomerAdminService $service, User $actor): void {
                $done = $service->anonymize($this->record, $actor);
                Notification::make()
                    ->{$done ? 'success' : 'warning'}()
                    ->title(__($done ? 'customers.notifications.anonymized' : 'customers.notifications.already_anonymized'))
                    ->send();
            }));
    }

    /**
     * Runs an admin action with the signed-in staff member and turns the expected failures into notifications.
     *
     * @param  Closure(CustomerAdminService, User): void  $callback
     */
    private function run(Closure $callback): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        try {
            $callback(app(CustomerAdminService::class), $actor);
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('customers.notifications.forbidden'))->send();
        } catch (CustomerAccountException|CustomerAuthException|CustomerThrottledException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }

        $this->record->refresh();
        $this->record->load(['group', 'company']);
    }
}
