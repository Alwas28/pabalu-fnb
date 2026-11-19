<?php

use App\Models\OutboxEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kirim event ke Analytics Platform / Central Dashboard (tidak melakukan apa pun bila ANALYTICS_ENDPOINT kosong).
Schedule::command('pabalu:outbox-dispatch')->everyMinute()->withoutOverlapping(10);
Schedule::command('model:prune', ['--model' => [OutboxEvent::class]])->daily();
