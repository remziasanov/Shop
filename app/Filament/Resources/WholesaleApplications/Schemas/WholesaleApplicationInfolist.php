<?php

declare(strict_types=1);

namespace App\Filament\Resources\WholesaleApplications\Schemas;

use App\Enums\WholesaleApplicationStatus;
use App\Models\WholesaleApplication;
use App\Services\Customers\WholesaleApplicationService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Card of a wholesale application (CG-22, CG-24): the data, the duplicate-INN warnings, the decision, the consent.
 */
final class WholesaleApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('wholesale.view.warnings'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->visible(fn (WholesaleApplication $record): bool => self::warnings($record) !== [])
                    ->schema([
                        TextEntry::make('warnings')
                            ->hiddenLabel()
                            ->state(fn (WholesaleApplication $record): array => self::warnings($record))
                            ->listWithLineBreaks()
                            ->color('warning'),
                    ]),

                Section::make(__('wholesale.view.application'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('wholesale.table.status'))
                            ->badge()
                            ->color(fn (WholesaleApplicationStatus $state): string => $state->color())
                            ->formatStateUsing(fn (WholesaleApplicationStatus $state): string => $state->label()),
                        TextEntry::make('created_at')->label(__('wholesale.table.created_at'))->dateTime('d.m.Y H:i'),
                        TextEntry::make('customer_id')
                            ->label(__('wholesale.table.customer'))
                            ->formatStateUsing(fn (int $state): string => __('wholesale.view.customer_link', ['id' => $state])),
                        TextEntry::make('company_name')->label(__('wholesale.fields.company_name'))->placeholder('—')->weight('bold'),
                        TextEntry::make('inn')->label(__('wholesale.fields.inn'))->placeholder('—')->copyable(),
                        TextEntry::make('kpp')->label(__('wholesale.fields.kpp'))->placeholder('—'),
                        TextEntry::make('contact_name')->label(__('wholesale.fields.contact_name'))->placeholder('—'),
                        TextEntry::make('phone')
                            ->label(__('wholesale.fields.phone'))
                            ->placeholder('—')
                            ->url(fn (WholesaleApplication $record): ?string => filled($record->phone) ? 'tel:'.$record->phone : null),
                        TextEntry::make('email')
                            ->label(__('wholesale.fields.email'))
                            ->placeholder('—')
                            ->url(fn (WholesaleApplication $record): ?string => filled($record->email) ? 'mailto:'.$record->email : null),
                        TextEntry::make('customer.group.name')->label(__('wholesale.view.customer_group'))->placeholder('—'),
                        TextEntry::make('comment')->label(__('wholesale.fields.comment'))->placeholder('—')->columnSpanFull(),
                    ]),

                Section::make(__('wholesale.view.decision'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('decided_at')->label(__('wholesale.table.decided_at'))->dateTime('d.m.Y H:i')->placeholder(__('wholesale.view.not_decided')),
                        TextEntry::make('decidedBy.name')->label(__('wholesale.table.decided_by'))->placeholder('—'),
                        TextEntry::make('decidedGroup.name')->label(__('wholesale.table.group'))->placeholder('—'),
                        TextEntry::make('reject_reason')->label(__('wholesale.view.reject_reason'))->placeholder('—')->columnSpanFull()
                            ->visible(fn (WholesaleApplication $record): bool => $record->status === WholesaleApplicationStatus::Rejected),
                        TextEntry::make('return_comment')->label(__('wholesale.view.return_comment'))->columnSpanFull()
                            ->visible(fn (WholesaleApplication $record): bool => filled($record->return_comment)),
                    ]),

                Section::make(__('wholesale.view.consent'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->collapsed()
                    ->schema([
                        TextEntry::make('consent_at')->label(__('wholesale.view.consent_at'))->dateTime('d.m.Y H:i')->placeholder('—'),
                        TextEntry::make('consent_ip')->label(__('wholesale.view.consent_ip'))->placeholder('—'),
                        TextEntry::make('consent_user_agent')->label(__('wholesale.view.consent_user_agent'))->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * @return list<string>
     */
    private static function warnings(WholesaleApplication $record): array
    {
        return app(WholesaleApplicationService::class)->duplicateWarnings($record);
    }
}
