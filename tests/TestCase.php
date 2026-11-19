<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pengaman: test memakai RefreshDatabase (mengosongkan database). Bila config ter-cache
     * (php artisan optimize / config:cache), env phpunit.xml diabaikan dan test akan menghapus
     * database dev. Hentikan sebelum itu terjadi.
     */
    protected function setUp(): void
    {
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Config sedang ter-cache sehingga test akan memakai database dev. Jalankan "php artisan config:clear" lalu ulangi test.');
        }

        parent::setUp();

        if (config('database.connections.'.config('database.default').'.database') !== ':memory:') {
            throw new RuntimeException('Test harus memakai database SQLite in-memory dari phpunit.xml.');
        }
    }
}
