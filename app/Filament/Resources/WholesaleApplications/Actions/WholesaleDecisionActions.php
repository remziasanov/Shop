<?php

declare(strict_types=1);

namespace App\Filament\Resources\WholesaleApplications\Actions;

use App\Exceptions\WholesaleApplicationException;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Customers\WholesaleApplicationService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The three decisions on a wholesale application (CG-22), shared by the list and the card. The actions only collect
 * the input; the rules and the writes are in {@see WholesaleApplicationService}, which also checks the rights.
 */
final class WholesaleDecisionActions
{
    /**
     * «Одобрить»: a required group, the wholesale default group preselected.
     */
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('wholesale.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (WholesaleApplication $record): bool => $record->isNew() && Gate::allows('decide', $record))
            ->modalHeading(__('wholesale.actions.approve_heading'))
            ->modalDescription(__('wholesale.actions.approve_description'))
            ->schema([
                Select::make('group_id')
                    ->label(__('wholesale.actions.group'))
                    ->options(fn (): array => self::assignableGroups())
                    ->default(fn (): ?int => self::defaultGroupId())
                    ->required()
                    ->native(false),
            ])
            ->action(fn (WholesaleApplication $record, array $data) => self::run(
                $record,
                'approved',
                fn (User $actor) => app(WholesaleApplicationService::class)->approve(
                    $record,
                    CustomerGroup::query()->findOrFail((int) $data['group_id']),
                    $actor,
                ),
            ));
    }

    /**
     * «Отклонить»: a required reason the customer will see.
     */
    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('wholesale.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (WholesaleApplication $record): bool => $record->isNew() && Gate::allows('decide', $record))
            ->modalHeading(__('wholesale.actions.reject_heading'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('wholesale.actions.reject_reason'))
                    ->helperText(__('wholesale.actions.reject_reason_hint'))
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(fn (WholesaleApplication $record, array $data) => self::run(
                $record,
                'rejected',
                fn (User $actor) => app(WholesaleApplicationService::class)->reject($record, (string) $data['reason'], $actor),
            ));
    }

    /**
     * «Вернуть в новые»: a required comment, Administrator only.
     */
    public static function returnToNew(): Action
    {
        return Action::make('return_to_new')
            ->label(__('wholesale.actions.return'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (WholesaleApplication $record): bool => $record->isDecided() && Gate::allows('returnToNew', $record))
            ->modalHeading(__('wholesale.actions.return_heading'))
            ->modalDescription(__('wholesale.actions.return_description'))
            ->schema([
                Textarea::make('comment')
                    ->label(__('wholesale.actions.return_comment'))
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(fn (WholesaleApplication $record, array $data) => self::run(
                $record,
                'returned',
                fn (User $actor) => app(WholesaleApplicationService::class)->returnToNew($record, (string) $data['comment'], $actor),
            ));
    }

    /**
     * Groups a customer can be moved to: active, not «Гость».
     *
     * @return array<int, string>
     */
    public static function assignableGroups(): array
    {
        return CustomerGroup::query()
            ->where('is_active', true)
            ->where('code', '!=', CustomerGroup::GUEST)
            ->ordered()
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The group flagged as the default of a wholesale approval (CG-22), when it can still be chosen.
     */
    public static function defaultGroupId(): ?int
    {
        $id = CustomerGroup::query()->where('is_wholesale_default', true)->where('is_active', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  Closure(User): mixed  $decision
     */
    private static function run(WholesaleApplication $record, string $result, Closure $decision): void
    {
        $actor = auth()->user();

        try {
            abort_unless($actor instanceof User, 403);
            $decision($actor);
        } catch (WholesaleApplicationException $e) {
            Notification::make()->danger()->title(__('wholesale.actions.failed'))->body($e->getMessage())->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        // The service works on a locked copy of the row; the page keeps showing the record it was opened with.
        $record->refresh();

        Notification::make()->success()->title(__('wholesale.actions.'.$result))->send();
    }
}
