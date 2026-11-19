<?php

namespace App\Console\Commands;

use App\Support\Analytics\Outbox;
use App\Support\Analytics\OutboxDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Kirim event outbox ke Analytics Platform. Dijadwalkan tiap menit (routes/console.php).
 *
 *   php artisan pabalu:outbox-dispatch --batches=10
 */
#[Signature('pabalu:outbox-dispatch {--batches=10 : Jumlah batch maksimum per eksekusi}')]
#[Description('Kirim event penjualan & keuangan ke Analytics Platform / Central Dashboard')]
class DispatchOutbox extends Command
{
    public function handle(OutboxDispatcher $dispatcher): int
    {
        if (! Outbox::enabled()) {
            $this->info('ANALYTICS_ENDPOINT belum diatur; tidak ada yang dikirim.');

            return self::SUCCESS;
        }

        $sent = 0;

        for ($batch = 0; $batch < max(1, (int) $this->option('batches')); $batch++) {
            $result = $dispatcher->dispatch();
            $sent += $result['sent'];

            if ($result['error'] !== null) {
                $this->error("Gagal mengirim {$result['failed']} event: {$result['error']}");

                return self::FAILURE;
            }

            if ($result['sent'] === 0) {
                break;
            }
        }

        $this->info("{$sent} event terkirim.");

        return self::SUCCESS;
    }
}
