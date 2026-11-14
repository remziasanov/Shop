@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('storefront.pagination.label') }}" class="mt-8">
        <ul class="flex flex-wrap items-center justify-center gap-2 text-sm">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="flex min-h-11 items-center rounded-btn border border-line px-3 py-2 text-faint" aria-disabled="true">{{ __('storefront.pagination.prev') }}</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="flex min-h-11 items-center rounded-btn border border-line-strong px-3 py-2 hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront.pagination.prev') }}</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="block px-2 py-2 text-muted" aria-hidden="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span class="flex min-h-11 min-w-11 items-center justify-center rounded-btn bg-primary px-3 py-2 text-center font-semibold text-on-primary" aria-current="page"><span class="sr-only">{{ __('storefront.pagination.page', ['page' => '']) }}</span>{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="flex min-h-11 min-w-11 items-center justify-center rounded-btn border border-line-strong px-3 py-2 text-center hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront.pagination.page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="flex min-h-11 items-center rounded-btn border border-line-strong px-3 py-2 hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront.pagination.next') }}</a>
                @else
                    <span class="flex min-h-11 items-center rounded-btn border border-line px-3 py-2 text-faint" aria-disabled="true">{{ __('storefront.pagination.next') }}</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
