<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Support;

use App\Models\PromoLink;
use App\Services\Promo\PromoLinkSaver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Link actions of PM-26: copy the public address, check the destination, preview the final address in a new tab.
 */
final class PromoLinkActions
{
    public static function copy(): Action
    {
        return Action::make('copy')
            ->label(__('promo_admin.links.actions.copy'))
            ->icon('heroicon-o-clipboard-document')
            ->color('gray')
            ->action(function (PromoLink $record, Component $livewire): void {
                $url = app(PromoLinkSaver::class)->publicUrl($record->slug);

                $livewire->js('window.navigator.clipboard.writeText('.json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT).')');

                Notification::make()->success()->title(__('promo_admin.links.actions.copied'))->body($url)->send();
            });
    }

    /**
     * «Проверить»: what the link does for a guest right now, or why it would send the visitor to the catalog.
     */
    public static function check(): Action
    {
        return Action::make('check')
            ->label(__('promo_admin.links.actions.check'))
            ->icon('heroicon-o-check-badge')
            ->color('gray')
            ->action(function (PromoLink $record): void {
                $resolution = app(PromoLinkSaver::class)->check($record);

                if ($resolution->ok) {
                    Notification::make()->success()
                        ->title(__('promo_admin.links.actions.check_ok', ['url' => (string) $resolution->url]))
                        ->body(app(PromoLinkSaver::class)->codeStatus($record))
                        ->send();

                    return;
                }

                Notification::make()->warning()
                    ->title(__('promo_admin.links.actions.check_failed', ['reason' => (string) $resolution->reasonText()]))
                    ->send();
            });
    }

    /**
     * «Предпросмотр»: opens the final address (with UTM) in a new tab; a link that would not work only explains why.
     */
    public static function preview(): Action
    {
        return Action::make('preview')
            ->label(__('promo_admin.links.actions.preview'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->url(function (PromoLink $record): ?string {
                $resolution = app(PromoLinkSaver::class)->check($record);

                return $resolution->ok ? url((string) $resolution->url) : null;
            })
            ->openUrlInNewTab()
            ->action(function (PromoLink $record): void {
                $resolution = app(PromoLinkSaver::class)->check($record);

                Notification::make()->warning()
                    ->title(__('promo_admin.links.actions.preview_unavailable', ['reason' => (string) $resolution->reasonText()]))
                    ->send();
            });
    }
}
