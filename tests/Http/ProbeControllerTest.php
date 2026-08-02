<?php

it('renders the dashboard without loading Tailwind or Alpine.js from a third-party CDN', function () {
    app()->instance('probe.auth', fn ($request) => true);

    $response = $this->get('/probe');

    $response->assertOk();
    $response->assertDontSee('cdn.tailwindcss.com', false);
    $response->assertDontSee('cdn.jsdelivr.net', false);
});
