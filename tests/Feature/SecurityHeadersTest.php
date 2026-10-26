<?php

declare(strict_types=1);

it('sends security headers (NF-13)', function () {
    $response = $this->get('/up');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});
