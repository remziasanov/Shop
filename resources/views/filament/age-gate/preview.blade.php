@props(['age' => '18', 'title' => '', 'text' => '', 'yes' => '', 'no' => ''])

{{-- AG-14: static preview of the storefront overlay; it reflects the form values as they are typed. --}}
<div class="rounded-lg bg-gray-900/80 p-6">
    <div class="mx-auto max-w-md rounded-lg bg-white p-6 text-center text-gray-900 shadow-xl">
        <p class="mb-3 inline-block rounded bg-red-700 px-3 py-1 text-lg font-bold text-white">{{ $age }}+</p>
        <h3 class="text-xl font-semibold">{{ $title }}</h3>
        <p class="mt-3 text-gray-700">{{ $text }}</p>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <span class="rounded bg-primary-600 px-5 py-3 font-medium text-white">{{ $yes }}</span>
            <span class="rounded border border-gray-300 px-5 py-3 font-medium">{{ $no }}</span>
        </div>
    </div>
</div>
