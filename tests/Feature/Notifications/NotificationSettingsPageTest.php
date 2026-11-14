<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Mail\TestMail;
use App\Models\User;
use App\Settings\NotificationSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('sends a test email to the first shop address (NT-04)', function (): void {
    Mail::fake();
    $settings = app(NotificationSettings::class);
    $settings->notify_emails = ['shop@example.com'];
    $settings->save();

    Livewire::test(ManageNotificationSettings::class)
        ->callAction(TestAction::make('sendTestMail')->schemaComponent('testMailActions', schema: 'form'))
        ->assertNotified();

    Mail::assertSent(TestMail::class, fn ($m): bool => $m->hasTo('shop@example.com'));
});

it('falls back to the admin email for the test mail', function (): void {
    Mail::fake();

    Livewire::test(ManageNotificationSettings::class)
        ->callAction(TestAction::make('sendTestMail')->schemaComponent('testMailActions', schema: 'form'));

    Mail::assertSent(TestMail::class, fn ($m): bool => $m->hasTo($this->admin->email));
});
