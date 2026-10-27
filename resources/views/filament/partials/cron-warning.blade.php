<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" data-testid="cron-warning">
    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400" />
    <span class="font-medium text-gray-950 dark:text-white">{{ __('dashboard.cron.warning') }}</span>
    <a href="{{ route('admin.help.cron') }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('dashboard.cron.instructions') }}</a>
</div>
