<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Aplikasi berjalan di balik Cloudflare: skema https diteruskan lewat header, host tidak. */
class TrustedProxyTest extends TestCase
{
    public function test_https_from_proxy_is_honoured_but_forwarded_host_is_ignored(): void
    {
        $this->get('/login', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Host' => 'situs-penipu.test',
        ])
            ->assertOk()
            ->assertSee('https://localhost/forgot-password', false)
            ->assertDontSee('situs-penipu.test');
    }
}
