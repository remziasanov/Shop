@include('errors._page', [
    'code' => null,
    'title' => __('seo.errors.closed.title'),
    'text' => ($message ?? '') !== '' ? $message : __('seo.errors.closed.text'),
    'links' => false,
])
