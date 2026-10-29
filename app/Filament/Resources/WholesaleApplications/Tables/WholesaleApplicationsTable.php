<?php

declare(strict_types=1);

namespace App\Filament\Resources\WholesaleApplications\Tables;

use App\Enums\WholesaleApplicationStatus;
use App\Filament\Resources\WholesaleApplications\Actions\WholesaleDecisionActions;
use App\Models\WholesaleApplication;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * List of wholesale applications (CG-22): newest first, the decision buttons in the row.
 */
final class WholesaleApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(self::columns())
            ->filters([
                SelectFilter::make('status')
                    ->label(__('wholesale.table.status'))
                    ->options(WholesaleApplicationStatus::options()),
            ])
            ->recordActions([
                WholesaleDecisionActions::approve(),
                WholesaleDecisionActions::reject(),
                ViewAction::make(),
            ]);
    }

    /**
     * The columns, shared with the «Заявки на опт» tab of a customer.
     *
     * @return list<TextColumn>
     */
    public static function columns(bool $withCustomer = true): array
    {
        $columns = [
            TextColumn::make('id')
                ->label(__('wholesale.table.id'))
                ->sortable()
                ->weight('bold'),
            TextColumn::make('status')
                ->label(__('wholesale.table.status'))
                ->badge()
                ->color(fn (WholesaleApplicationStatus $state): string => $state->color())
                ->formatStateUsing(fn (WholesaleApplicationStatus $state): string => $state->label()),
            TextColumn::make('company_name')
                ->label(__('wholesale.fields.company_name'))
                ->placeholder('—')
                ->searchable(),
            TextColumn::make('inn')
                ->label(__('wholesale.fields.inn'))
                ->placeholder('—')
                ->searchable()
                ->copyable(),
            TextColumn::make('contact_name')
                ->label(__('wholesale.fields.contact_name'))
                ->placeholder('—')
                ->searchable(),
            TextColumn::make('phone')
                ->label(__('wholesale.fields.phone'))
                ->placeholder('—')
                ->url(fn (WholesaleApplication $record): ?string => filled($record->phone) ? 'tel:'.$record->phone : null)
                ->color('gray'),
        ];

        if ($withCustomer) {
            $columns[] = TextColumn::make('customer_id')
                ->label(__('wholesale.table.customer'))
                ->formatStateUsing(fn (int $state): string => '#'.$state)
                ->sortable();
        }

        return [
            ...$columns,
            TextColumn::make('created_at')
                ->label(__('wholesale.table.created_at'))
                ->dateTime('d.m.Y H:i')
                ->sortable(),
            TextColumn::make('decided_at')
                ->label(__('wholesale.table.decided_at'))
                ->dateTime('d.m.Y H:i')
                ->placeholder('—')
                ->sortable(),
            TextColumn::make('decidedGroup.name')
                ->label(__('wholesale.table.group'))
                ->placeholder('—'),
        ];
    }
}
