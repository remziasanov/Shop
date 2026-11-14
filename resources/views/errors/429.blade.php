@include('errors._page', ['code' => 429, 'title' => __('seo.errors.429.title'), 'text' => $exception->getMessage() !== '' ? $exception->getMessage() : __('seo.errors.429.text'), 'links' => true])
