<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Telegram\TelegramBotSetup;
use App\Services\Telegram\TelegramLinkService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The staff member's own Telegram link (TG-07 `s_` token, the minimal part of TS-02): shows the linked state,
 * issues a 15-minute link into the bot and disconnects. Linking is confirmed in the bot, never by the link alone.
 * Open to every signed-in staff user; it is reached from the user menu, not from the navigation.
 */
class MyTelegram extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'my-telegram';

    protected string $view = 'filament.pages.my-telegram';

    public ?string $link = null;

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public function getTitle(): string
    {
        return __('telegram.staff.page_title');
    }

    public function botAvailable(): bool
    {
        return app(TelegramBotSetup::class)->isConnected();
    }

    public function linkedUsername(): ?string
    {
        $account = $this->user()->telegramAccount;

        return $account === null ? null : (filled($account->username) ? '@'.$account->username : (string) $account->first_name);
    }

    public function isLinked(): bool
    {
        return $this->user()->telegramAccount !== null;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(__('telegram.staff.connect'))
                ->visible(fn (): bool => $this->botAvailable() && ! $this->isLinked())
                ->action(function (): void {
                    $this->link = app(TelegramLinkService::class)->staffLink($this->user());
                }),
            Action::make('disconnect')
                ->label(__('telegram.staff.disconnect'))
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->isLinked())
                ->action(function (): void {
                    app(TelegramLinkService::class)->unlinkStaff($this->user());
                    $this->link = null;
                    $this->user()->unsetRelation('telegramAccount');
                    Notification::make()->success()->title(__('telegram.staff.disconnected'))->send();
                }),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
