<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PhoneVerificationMethod;
use App\Enums\PhoneVerificationOutcome;
use App\Enums\PhoneVerificationPurpose;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Users\PermissionService;
use App\Support\PiiMask;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Подтверждения телефона» (PV-09): the journal of the last 30 days (date, masked number, method, outcome, cost) and the
 * totals of the current month. Read-only; opened with the right `settings.phone_verification.view`.
 */
class PhoneVerificationJournal extends Page implements HasTable
{
    use InteractsWithTable;

    /** The journal shows this many days (the records are deleted after 90 by shop:cleanup). */
    public const DAYS = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 57;

    protected static ?string $slug = 'phone-verifications';

    protected string $view = 'filament.pages.phone-verification-journal';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, 'settings.phone_verification.view');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('phone_verification.journal.title');
    }

    public function getTitle(): string
    {
        return __('phone_verification.journal.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PhoneVerification::query()->where('created_at', '>=', now()->subDays(self::DAYS)->startOfDay()))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('phone_verification.journal.date'))->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('phone')->label(__('phone_verification.journal.phone'))->formatStateUsing(fn (string $state): string => PiiMask::phone($state)),
                TextColumn::make('purpose')->label(__('phone_verification.journal.purpose'))->formatStateUsing(fn (PhoneVerificationPurpose|string $state): string => $state instanceof PhoneVerificationPurpose ? $state->label() : $state),
                TextColumn::make('method')->label(__('phone_verification.journal.method'))->formatStateUsing(fn (PhoneVerificationMethod|string $state): string => $state instanceof PhoneVerificationMethod ? $state->label() : $state),
                TextColumn::make('outcome')->label(__('phone_verification.journal.outcome'))->badge()
                    ->formatStateUsing(fn (PhoneVerificationOutcome|string $state): string => $state instanceof PhoneVerificationOutcome ? $state->label() : $state)
                    ->color(fn (PhoneVerificationOutcome|string $state): string => match ($state) {
                        PhoneVerificationOutcome::Verified => 'success',
                        PhoneVerificationOutcome::Pending => 'gray',
                        PhoneVerificationOutcome::SkippedProvider, PhoneVerificationOutcome::Expired => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('cost')->label(__('phone_verification.journal.cost'))->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : self::roundedCost($state).' ₽'),
            ])
            ->filters([
                SelectFilter::make('outcome')->label(__('phone_verification.journal.outcome'))
                    ->options(collect(PhoneVerificationOutcome::cases())->mapWithKeys(fn (PhoneVerificationOutcome $case): array => [$case->value => $case->label()])->all()),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * The current month (PV-09): the requests the provider accepted and what they cost, rounded to kopecks.
     *
     * @return array{count: int, cost: string}
     */
    public function monthTotals(): array
    {
        $query = PhoneVerification::query()->acceptedByProvider()->where('created_at', '>=', now()->startOfMonth());

        return ['count' => (clone $query)->count(), 'cost' => self::roundedCost((string) (clone $query)->sum('cost'))];
    }

    /**
     * Half-up rounding of a decimal string to 2 places without floats (provider prices have fractions of a kopeck).
     */
    public static function roundedCost(string $cost): string
    {
        $cost = is_numeric($cost) ? $cost : '0';

        return bcadd(bcadd($cost, '0.005', 4), '0', 2);
    }
}
