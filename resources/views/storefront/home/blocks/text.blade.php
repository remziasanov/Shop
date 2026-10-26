{{-- Home page text block (5.2). $content is sanitized HTML (NF-10). --}}
<section class="mb-8">
    @if ($title ?? null)
        <h2 class="mb-3 text-xl font-bold md:text-2xl">{{ $title }}</h2>
    @endif
    <div class="max-w-3xl leading-relaxed [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-slate-300 [&_blockquote]:pl-4 [&_h2]:mb-2 [&_h2]:mt-4 [&_h2]:text-xl [&_h2]:font-bold [&_h3]:mb-2 [&_h3]:mt-4 [&_h3]:text-lg [&_h3]:font-semibold [&_img]:h-auto [&_img]:max-w-full [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mb-3 [&_table]:w-full [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2 [&_ul]:list-disc [&_ul]:pl-6">{!! $content !!}</div>
</section>
