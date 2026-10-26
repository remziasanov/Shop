<x-filament-panels::page>
    @include('filament.resources.imports.partials.styles')

    @php
        $step = $this->step();
        $import = $this->currentImport();
    @endphp

    <div class="imp-stack">
        @include('filament.resources.imports.partials.steps', ['current' => $step])

        {{-- Step 1: file --}}
        @if ($step === 'file')
            <x-filament::section :heading="__('import_ui.file.heading')">
                <form wire:submit="uploadFile" class="imp-stack">
                    {{ $this->form }}

                    <div class="imp-row">
                        <x-filament::button type="submit" wire:target="uploadFile">
                            {{ __('import_ui.file.next') }}
                        </x-filament::button>
                        <x-filament::link :href="route('admin.import.template')" icon="heroicon-o-arrow-down-tray">
                            {{ __('import_ui.file.template') }}
                        </x-filament::link>
                        <x-filament::link :href="\App\Filament\Resources\Imports\ImportResource::getUrl('index')" color="gray">
                            {{ __('import_ui.wizard.back_to_journal') }}
                        </x-filament::link>
                    </div>
                </form>
            </x-filament::section>

        {{-- Step 2: mapping and options --}}
        @elseif ($step === 'mapping')
            <form wire:submit="saveMapping" class="imp-stack">
                <x-filament::section :heading="__('import_ui.mapping.heading')" :description="__('import_ui.mapping.description')">
                    @if ($this->columnsError)
                        <div class="imp-note imp-note-danger">{{ $this->columnsError }}</div>
                    @else
                        <div class="imp-stack">
                            @if (count($sheetNames) > 1)
                                <div style="max-width: 24rem;">
                                    <label class="imp-muted" style="font-size: .85rem;">{{ __('import_ui.mapping.sheet') }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model.live="sheet">
                                            @foreach ($sheetNames as $sheetName)
                                                <option value="{{ $sheetName }}">{{ $sheetName }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    <div class="imp-muted" style="font-size: .8rem;">{{ __('import_ui.mapping.sheet_help') }}</div>
                                </div>
                            @endif

                            @error('mapping')
                                <div class="imp-note imp-note-danger">{{ $message }}</div>
                            @enderror

                            @foreach (array_values(array_unique([...$mappingWarnings, ...$this->liveMappingWarnings()])) as $warning)
                                <div class="imp-note imp-note-warning">{{ $warning }}</div>
                            @endforeach

                            <div class="imp-scroll">
                                <table class="imp-table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('import_ui.mapping.column') }}</th>
                                            <th style="min-width: 15rem;">{{ __('import_ui.mapping.target') }}</th>
                                            <th>{{ __('import_ui.mapping.samples') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($headers as $index => $header)
                                            <tr wire:key="map-{{ $index }}">
                                                <td>{{ $header !== '' ? $header : __('import_ui.mapping.header_blank', ['n' => $index + 1]) }}</td>
                                                <td>
                                                    <x-filament::input.wrapper>
                                                        <x-filament::input.select wire:model.live="mapping.{{ $index }}">
                                                            @foreach ($this->targetOptions() as $value => $label)
                                                                <option value="{{ $value }}">{{ $label }}</option>
                                                            @endforeach
                                                        </x-filament::input.select>
                                                    </x-filament::input.wrapper>
                                                </td>
                                                <td class="imp-muted">
                                                    @foreach ($samples as $row)
                                                        <div class="imp-sample" title="{{ $row[$index] ?? '' }}">{{ ($row[$index] ?? '') !== '' ? $row[$index] : '—' }}</div>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </x-filament::section>

                @if (! $this->columnsError)
                    <x-filament::section :heading="__('import_ui.mapping.options_heading')">
                        <div class="imp-grid">
                            <div>
                                <label class="imp-muted" style="font-size: .85rem;">{{ __('import_ui.mapping.mode') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="options.mode">
                                        @foreach ($this->modeOptions() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                @if ($this->canUseFullSync())
                                    <div class="imp-muted" style="font-size: .8rem;">{{ __('import_ui.mapping.mode_full_sync_help') }}</div>
                                @endif
                                @error('options.mode')
                                    <div class="imp-note imp-note-danger">{{ $message }}</div>
                                @enderror
                            </div>
                            <div>
                                <label class="imp-muted" style="font-size: .85rem;">{{ __('import_ui.mapping.category_separator') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="options.category_separator">
                                        @foreach (__('import.category_separators') as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                            <div>
                                <label class="imp-muted" style="font-size: .85rem;">{{ __('import_ui.mapping.empty_cells') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="options.empty_cell_policy">
                                        @foreach (\App\Enums\EmptyCellPolicy::options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                            <div>
                                <label class="imp-muted" style="font-size: .85rem;">{{ __('import_ui.mapping.photo_mode') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="options.photo_mode">
                                        @foreach (\App\Enums\PhotoMode::options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        </div>

                        <div class="imp-stack" style="margin-top: 1rem;">
                            <label class="imp-row">
                                <x-filament::input.checkbox wire:model="options.create_categories" />
                                <span>{{ __('import_ui.mapping.create_categories') }}</span>
                            </label>
                            <label class="imp-row">
                                <x-filament::input.checkbox wire:model="options.dry_run" />
                                <span>{{ __('import_ui.mapping.dry_run') }}</span>
                            </label>
                            <div class="imp-muted" style="font-size: .8rem;">{{ __('import_ui.mapping.dry_run_help') }}</div>
                            @foreach (['options.create_categories', 'options.category_separator', 'options.empty_cell_policy', 'options.photo_mode', 'options.dry_run'] as $key)
                                @error($key)
                                    <div class="imp-note imp-note-danger">{{ $message }}</div>
                                @enderror
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif

                <div class="imp-row">
                    @if (! $this->columnsError)
                        <x-filament::button type="submit" wire:target="saveMapping">{{ __('import_ui.mapping.next') }}</x-filament::button>
                    @endif
                    <x-filament::link :href="\App\Filament\Resources\Imports\ImportResource::getUrl('create')" color="gray">
                        {{ __('import_ui.mapping.back') }}
                    </x-filament::link>
                </div>
            </form>

        {{-- Step 3: check --}}
        @elseif ($import !== null)
            @php
                $analysis = $import->analysis ?? [];
                $summary = $analysis['summary'] ?? [];
                $status = $import->status;
            @endphp

            <div @if ($status === \App\Enums\ImportStatus::Analyzing) wire:poll.2s @endif class="imp-stack">
                @if ($status === \App\Enums\ImportStatus::Analyzing)
                    <x-filament::section :heading="__('import_ui.check.heading')">
                        <div class="imp-stack">
                            <div class="imp-row">
                                <x-filament::loading-indicator class="h-5 w-5" />
                                <span>{{ __('import_ui.check.analyzing') }}</span>
                            </div>
                            @if ($this->analysisIsStuck($import))
                                <div class="imp-note imp-note-warning">{{ __('import_ui.check.analyzing_stuck') }}</div>
                                <div>
                                    <x-filament::button wire:click="analyzeNow" wire:loading.attr="disabled" color="gray">
                                        {{ __('import_ui.check.analyze_now') }}
                                    </x-filament::button>
                                </div>
                            @endif
                        </div>
                    </x-filament::section>
                @elseif ($status === \App\Enums\ImportStatus::Failed)
                    <x-filament::section :heading="__('import_ui.check.failed')">
                        <div class="imp-stack">
                            <div class="imp-note imp-note-danger">{{ $import->failure_message }}</div>
                            <div>
                                <x-filament::button wire:click="backToMapping" color="gray">{{ __('import_ui.check.back') }}</x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>
                @elseif ($status === \App\Enums\ImportStatus::Analyzed)
                    <x-filament::section :heading="__('import_ui.check.heading')">
                        <div class="imp-stack">
                            <div class="imp-grid">
                                <div class="imp-stat"><div class="imp-stat-value">{{ $summary['create'] ?? 0 }}</div><div class="imp-stat-label">{{ __('import_ui.check.summary_create') }}</div></div>
                                <div class="imp-stat"><div class="imp-stat-value">{{ $summary['update'] ?? 0 }}</div><div class="imp-stat-label">{{ __('import_ui.check.summary_update') }}@if (($summary['restored'] ?? 0) > 0) ({{ __('import_ui.check.summary_restored', ['n' => $summary['restored']]) }})@endif</div></div>
                                <div class="imp-stat"><div class="imp-stat-value">{{ $summary['skip'] ?? 0 }}</div><div class="imp-stat-label">{{ __('import_ui.check.summary_skip') }}</div></div>
                                <div class="imp-stat imp-stat-danger"><div class="imp-stat-value">{{ $summary['errors'] ?? 0 }}</div><div class="imp-stat-label">{{ __('import_ui.check.summary_errors') }}</div></div>
                                <div class="imp-stat imp-stat-warning"><div class="imp-stat-value">{{ $summary['warnings'] ?? 0 }}</div><div class="imp-stat-label">{{ __('import_ui.check.summary_warnings') }}</div></div>
                            </div>

                            @if (($summary['categories_rows'] ?? 0) > 0)
                                <div class="imp-muted">{{ __('import_ui.check.categories', ['rows' => $summary['categories_rows'], 'errors' => $summary['categories_errors'] ?? 0]) }}</div>
                            @endif

                            @if (($summary['errors'] ?? 0) > 0)
                                <div class="imp-note imp-note-warning">{{ __('import_ui.check.error_rows_note') }}</div>
                            @endif

                            @if ($import->dry_run)
                                <div class="imp-note imp-note-info">{{ __('import_ui.check.dry_run_note') }}</div>
                            @endif

                            @foreach ($analysis['mapping_warnings'] ?? [] as $warning)
                                <div class="imp-note imp-note-warning">{{ $warning }}</div>
                            @endforeach
                        </div>
                    </x-filament::section>

                    <x-filament::section :heading="__('import_ui.check.preview_heading')">
                        @php
                            $columns = $this->previewColumns($import);
                        @endphp
                        <div class="imp-scroll">
                            <table class="imp-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('import_ui.check.preview_row') }}</th>
                                        <th>{{ __('import_ui.check.preview_result') }}</th>
                                        @foreach ($columns as $column)
                                            <th>{{ $column['label'] }}</th>
                                        @endforeach
                                        <th>{{ __('import_ui.check.preview_issues') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($analysis['preview'] ?? [] as $row)
                                        @php
                                            $errorsByField = [];
                                            $rowLevelErrors = [];
                                            foreach ($row['errors'] ?? [] as $error) {
                                                if (($error['field'] ?? '') === '') {
                                                    $rowLevelErrors[] = $error;
                                                } else {
                                                    $errorsByField[str_starts_with($error['field'], 'attr:') ? 'attributes' : $error['field']][] = $error;
                                                }
                                            }
                                            $warningsByField = [];
                                            foreach ($row['warnings'] ?? [] as $warning) {
                                                if (($warning['field'] ?? '') !== '') {
                                                    $warningsByField[str_starts_with($warning['field'], 'attr:') ? 'attributes' : $warning['field']][] = $warning;
                                                }
                                            }
                                        @endphp
                                        <tr wire:key="preview-{{ $row['row'] }}">
                                            <td>{{ $row['row'] }}</td>
                                            <td>{{ __('import_ui.check.actions.'.($row['action'] ?? 'error')) }}</td>
                                            @foreach ($columns as $column)
                                                @php
                                                    $cellErrors = $errorsByField[$column['field']] ?? [];
                                                    $cellWarnings = $warningsByField[$column['field']] ?? [];
                                                    $value = $this->formatCell($column['field'], $row);
                                                @endphp
                                                <td @class(['imp-cell-error' => $cellErrors !== [], 'imp-cell-warning' => $cellErrors === [] && $cellWarnings !== []])
                                                    @if ($cellErrors !== [] || $cellWarnings !== []) title="{{ collect([...$cellErrors, ...$cellWarnings])->pluck('message')->implode('; ') }}" @endif>
                                                    {{ $value }}
                                                    @foreach ($cellErrors as $error)
                                                        <small>{{ $error['message'] }}</small>
                                                    @endforeach
                                                </td>
                                            @endforeach
                                            <td @class(['imp-cell-error' => $rowLevelErrors !== []])>
                                                @foreach ($rowLevelErrors as $error)
                                                    <small>{{ $error['message'] }}</small>
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>

                    <x-filament::section>
                        <div class="imp-stack">
                            @if ($import->mode === \App\Enums\ImportMode::FullSync)
                                @php($affected = $this->fullSyncAffected($import))
                                <div class="imp-note imp-note-danger">
                                    <strong>{{ __('import_ui.check.full_sync_affected', ['n' => $affected]) }}</strong>
                                </div>
                                <label class="imp-row">
                                    <x-filament::input.checkbox wire:model="confirmFullSync" />
                                    <span>{{ __('import_ui.check.full_sync_confirm', ['n' => $affected]) }}</span>
                                </label>
                                @error('confirmFullSync')
                                    <div class="imp-note imp-note-danger">{{ $message }}</div>
                                @enderror
                            @endif

                            <div class="imp-row">
                                <x-filament::button wire:click="startImport" wire:loading.attr="disabled" wire:target="startImport">
                                    {{ __('import_ui.check.start') }}
                                </x-filament::button>
                                <x-filament::button wire:click="backToMapping" color="gray">
                                    {{ __('import_ui.check.back') }}
                                </x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>
                @else
                    <div class="imp-note imp-note-warning">{{ __('import_ui.check.not_ready') }}</div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
