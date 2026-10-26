<x-filament-panels::page>
    @include('filament.resources.imports.partials.styles')

    @php
        $import = $this->importRecord();
        $status = $import->status;
        $running = $status->isRunning();
        $finished = $status->isFinished();
        $progress = $this->progress();
        $analysis = $import->analysis ?? [];
        $result = $analysis['result'] ?? [];
        $stalled = $progress->needsBrowserFallback($import);
        $hasProgress = $import->rows_total > 0 || $import->chunks_total > 0;
        $badgeColor = match ($status) {
            \App\Enums\ImportStatus::Completed => 'success',
            \App\Enums\ImportStatus::CompletedWithErrors => 'warning',
            \App\Enums\ImportStatus::Failed => 'danger',
            \App\Enums\ImportStatus::Queued, \App\Enums\ImportStatus::Processing, \App\Enums\ImportStatus::Analyzing => 'info',
            default => 'gray',
        };
    @endphp

    <div @if ($running) wire:poll.2s @endif class="imp-stack">
        @include('filament.resources.imports.partials.steps', ['current' => $finished ? 'report' : 'process'])

        <x-filament::section>
            <div class="imp-grid">
                <div>
                    <div class="imp-stat-label">{{ __('import_ui.report.file') }}</div>
                    <div>{{ $import->original_name }}</div>
                </div>
                <div>
                    <div class="imp-stat-label">{{ __('import_ui.report.user') }}</div>
                    <div>{{ $import->user?->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="imp-stat-label">{{ __('import_ui.report.mode') }}</div>
                    <div>{{ $import->mode->label() }}@if ($import->dry_run) ({{ __('import_ui.table.dry_run') }})@endif</div>
                </div>
                <div>
                    <div class="imp-stat-label">{{ __('import_ui.table.status') }}</div>
                    <x-filament::badge :color="$badgeColor">{{ $status->label() }}</x-filament::badge>
                </div>
                <div>
                    <div class="imp-stat-label">{{ __('import_ui.report.started_at') }}</div>
                    <div>{{ $import->started_at?->format('d.m.Y H:i:s') ?? '—' }}</div>
                </div>
                <div>
                    <div class="imp-stat-label">{{ __('import_ui.report.duration') }}</div>
                    <div>{{ $progress->durationLabel($import) }}</div>
                </div>
            </div>
        </x-filament::section>

        @if ($import->dry_run)
            <div class="imp-note imp-note-info">{{ __('import_ui.process.dry_run') }}</div>
        @endif

        {{-- Progress (IM-09) --}}
        @if ($running)
            <x-filament::section :heading="__('import_ui.process.heading')">
                <div class="imp-stack"
                     data-url="{{ route('admin.imports.process-next', $import) }}"
                     data-token="{{ csrf_token() }}"
                     data-error="{{ __('import_ui.process.browser_error') }}"
                     data-network="{{ __('import_ui.process.browser_network') }}"
                     x-data="{
                        running: false,
                        error: null,
                        async run() {
                            const root = this.$root.dataset;
                            this.running = true;
                            this.error = null;
                            let failures = 0;
                            while (true) {
                                let delay = 400;
                                try {
                                    const response = await fetch(root.url, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: { 'X-CSRF-TOKEN': root.token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    });
                                    const body = await response.json().catch(() => ({}));
                                    if (response.status === 503 && body.retry && failures < 3) {
                                        // Temporary server error: the import is still running, try again with a growing pause.
                                        failures++;
                                        this.error = body.error || root.error;
                                        delay = 2000 * Math.pow(2, failures - 1);
                                    } else if (! response.ok) {
                                        this.error = body.error || root.error;
                                        break;
                                    } else {
                                        failures = 0;
                                        this.error = null;
                                        $wire.$refresh();
                                        if (! body.more) {
                                            break;
                                        }
                                    }
                                } catch (e) {
                                    failures++;
                                    this.error = root.network;
                                    if (failures >= 3) {
                                        break;
                                    }
                                    delay = 2000 * Math.pow(2, failures - 1);
                                }
                                await new Promise((resolve) => setTimeout(resolve, delay));
                            }
                            this.running = false;
                            $wire.$refresh();
                        },
                     }">
                    @if ($hasProgress)
                        <div class="imp-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $import->progressPercent() }}">
                            <div style="width: {{ $import->progressPercent() }}%;"></div>
                        </div>
                        <div class="imp-row">
                            <strong>{{ $import->progressPercent() }}%</strong>
                            <span class="imp-muted">{{ __('import_ui.process.rows', ['done' => $import->rows_processed, 'total' => $import->rows_total]) }}</span>
                            <span class="imp-muted">{{ __('import_ui.process.chunks', ['done' => $import->chunks_done, 'total' => $import->chunks_total]) }}</span>
                        </div>
                    @else
                        <div class="imp-row">
                            <x-filament::loading-indicator class="h-5 w-5" />
                            <span>{{ __('import_ui.process.waiting') }}</span>
                        </div>
                    @endif

                    <div class="imp-grid">
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->created_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.created') }}</div></div>
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->updated_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.updated') }}</div></div>
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->unchanged_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.unchanged') }}</div></div>
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->skipped_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.skipped') }}</div></div>
                        <div class="imp-stat imp-stat-danger"><div class="imp-stat-value">{{ $import->error_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.errors') }}</div></div>
                        <div class="imp-stat imp-stat-warning"><div class="imp-stat-value">{{ $import->warning_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.warnings') }}</div></div>
                    </div>

                    {{-- Browser fallback (IM-10): the root element stays while the import runs so the loop keeps its state. --}}
                    <div x-show="running" x-cloak class="imp-note imp-note-info">
                        <strong>{{ __('import_ui.process.dont_close') }}.</strong> {{ __('import_ui.process.browser_running') }}
                    </div>
                    <div x-show="error" x-cloak class="imp-note imp-note-danger" x-text="error"></div>
                    @if ($stalled)
                        <div class="imp-note imp-note-warning" x-show="! running">
                            <div class="imp-stack">
                                <span>{{ __('import_ui.process.browser_hint') }}</span>
                                <div>
                                    <x-filament::button color="warning" x-on:click="run()" id="import-browser-fallback">
                                        {{ __('import_ui.process.browser_button') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- Report (IM-12) --}}
        @if ($finished)
            <x-filament::section :heading="__('import_ui.report.heading')">
                <div class="imp-stack">
                    @if ($status === \App\Enums\ImportStatus::Failed && filled($import->failure_message))
                        <div class="imp-note imp-note-danger">
                            <strong>{{ __('import_ui.report.failure') }}:</strong> {{ $import->failure_message }}
                        </div>
                    @endif

                    <div class="imp-grid">
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->created_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.created') }}</div></div>
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->updated_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.updated') }}</div></div>
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->unchanged_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.unchanged') }}</div></div>
                        <div class="imp-stat"><div class="imp-stat-value">{{ $import->skipped_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.skipped') }}</div></div>
                        <div class="imp-stat imp-stat-danger"><div class="imp-stat-value">{{ $import->error_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.errors') }}</div></div>
                        <div class="imp-stat imp-stat-warning"><div class="imp-stat-value">{{ $import->warning_count }}</div><div class="imp-stat-label">{{ __('import_ui.report.warnings') }}</div></div>
                        <div class="imp-stat"><div class="imp-stat-value">{{ $progress->durationLabel($import) }}</div><div class="imp-stat-label">{{ __('import_ui.report.duration') }}</div></div>
                        @if (isset($result['categories_created']))
                            <div class="imp-stat"><div class="imp-stat-value">{{ $result['categories_created'] }}</div><div class="imp-stat-label">{{ __('import_ui.report.categories_created') }}</div></div>
                        @endif
                        @if (isset($result['deactivated']))
                            <div class="imp-stat"><div class="imp-stat-value">{{ $result['deactivated'] }}</div><div class="imp-stat-label">{{ __('import_ui.report.deactivated') }}</div></div>
                        @endif
                    </div>

                    @if (! $this->sourceFileExists($import))
                        <div class="imp-muted">{{ __('import_ui.report.files_removed', ['days' => config('shop.import_retention_days')]) }}</div>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('import_ui.report.issues_heading')">
                @php
                    $issues = $this->issues($import);
                @endphp
                <div class="imp-stack">
                    <div style="max-width: 16rem;">
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="levelFilter">
                                <option value="all">{{ __('import_ui.report.filter_all') }}</option>
                                <option value="error">{{ __('import_ui.report.filter_error') }}</option>
                                <option value="warning">{{ __('import_ui.report.filter_warning') }}</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    @if ($issues->isEmpty())
                        <div class="imp-muted">{{ __('import_ui.report.issues_empty') }}</div>
                    @else
                        <div class="imp-scroll">
                            <table class="imp-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('import_ui.report.issue_row') }}</th>
                                        <th>{{ __('import_ui.report.issue_column') }}</th>
                                        <th>{{ __('import_ui.report.issue_level') }}</th>
                                        <th>{{ __('import_ui.report.issue_message') }}</th>
                                        <th>{{ __('import_ui.report.issue_value') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($issues as $issue)
                                        <tr wire:key="issue-{{ $issue->id }}">
                                            <td>{{ $issue->row_number }}</td>
                                            <td>{{ $issue->column ?? '—' }}</td>
                                            <td>
                                                <x-filament::badge :color="$issue->level === \App\Enums\ImportIssueLevel::Error ? 'danger' : 'warning'">
                                                    {{ __('import.issue_level.'.$issue->level->value) }}
                                                </x-filament::badge>
                                            </td>
                                            <td>{{ $issue->message }}</td>
                                            <td class="imp-muted">{{ $issue->value ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <x-filament::pagination :paginator="$issues" />
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
