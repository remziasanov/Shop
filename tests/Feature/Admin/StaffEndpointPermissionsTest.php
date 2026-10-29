<?php

declare(strict_types=1);

/**
 * Admin file and help endpoints check the permission of the feature they belong to, not only the sign-in (RB-01, RB-12).
 */
it('serves the import template only to staff who may run imports', function (): void {
    $this->actingAs(staffWith(['dashboard.view', 'imports.view']))->get(route('admin.import.template'))->assertForbidden();

    $this->actingAs(staffWith(['imports.run']))->get(route('admin.import.template'))->assertOk();
});

it('refuses the import template to an inactive staff member', function (): void {
    $user = staffWith(['imports.run']);
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get(route('admin.import.template'))->assertForbidden();
});

it('shows the cron instructions only to staff with the dashboard or the system status page', function (): void {
    $this->actingAs(staffWith(['products.view']))->get(route('admin.help.cron'))->assertForbidden();

    $this->actingAs(staffWith(['dashboard.view']))->get(route('admin.help.cron'))->assertOk();
    $this->actingAs(staffWith(['system.view']))->get(route('admin.help.cron'))->assertOk();
});
