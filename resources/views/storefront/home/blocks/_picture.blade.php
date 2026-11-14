{{-- Image of a banner / slide (5.2), responsive sources. $class: classes of the <img>, $eager: first screen image (NF-03). --}}
<picture>
    @if ($slide['mobileSrcset'])
        <source media="(max-width: 767px)" srcset="{{ $slide['mobileSrcset'] }}" sizes="100vw">
    @endif
    <img src="{{ $slide['src'] }}" srcset="{{ $slide['srcset'] }}" sizes="{{ $sizes ?? '(min-width: 1280px) 1216px, 100vw' }}"
         width="{{ $slide['width'] }}" height="{{ $slide['height'] }}" alt="{{ $slide['alt'] }}"
         @if ($eager) fetchpriority="high" @else loading="lazy" @endif decoding="async"
         class="{{ $class }}">
</picture>
