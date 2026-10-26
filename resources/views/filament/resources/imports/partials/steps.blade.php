@php
    $keys = ['file', 'mapping', 'check', 'process', 'report'];
    $position = array_search($current, $keys, true);
@endphp
<ol class="imp-steps">
    @foreach ($keys as $index => $key)
        <li class="imp-step {{ $index === $position ? 'imp-step-current' : ($index < $position ? 'imp-step-done' : '') }}">
            <span>{{ $index + 1 }}.</span> {{ __('import_ui.wizard.steps.'.$key) }}
        </li>
    @endforeach
</ol>
