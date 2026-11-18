<?php

namespace App\Support\Receipt;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * Tiket dapur ESC/POS (tanpa harga): meja/layanan, nomor order, item + modifier + catatan.
 * Untuk outlet mode "cetak tiket". Satu tiket per batch — pesanan awal = batch 1,
 * tiap tambahan open bill = batch berikutnya dan ditandai "TAMBAHAN".
 */
class EscPosKitchenTicket extends EscPosDocument
{
    /** @var Collection<int, OrderItem> */
    private Collection $items;

    public function __construct(private Order $order, private ?int $batch = null, ?int $paperWidth = null)
    {
        $order->loadMissing(['outlet.business', 'cashier', 'items.modifiers']);
        $this->batch ??= (int) $order->items->max('kitchen_batch');
        $this->items = self::itemsFor($order, $this->batch);
        $this->setPaperWidth($paperWidth ?? $order->outlet->receipt_paper_width);
        $this->compose();
    }

    /** @return Collection<int, OrderItem> */
    public static function itemsFor(Order $order, ?int $batch): Collection
    {
        return $order->items->where('quantity', '>', 0)->when($batch, fn ($items) => $items->where('kitchen_batch', $batch))->values();
    }

    public function batch(): int
    {
        return (int) $this->batch;
    }

    private function compose(): void
    {
        $order = $this->order;
        $time = now($order->outlet->business->timezone ?: config('app.timezone'));

        $this->align('center')->bold(true)->line('TIKET DAPUR');
        if ($this->batch > 1) {
            $this->line('*** TAMBAHAN #'.($this->batch - 1).' ***');
        }
        $this->size(true)->line($order->table_number ? 'MEJA '.$order->table_number : strtoupper($order->service_type->label()))->size(false)->bold(false);

        $this->align('left')->rule();
        $this->pair('No', $order->number);
        $this->pair('Jam', $time->format('H:i'));
        if ($order->customer_name) {
            $this->pair('Nama', $order->customer_name);
        }
        if ($order->delivery_channel) {
            $this->pair('Kanal', $order->delivery_channel->label().($order->delivery_reference ? ' #'.$order->delivery_reference : ''));
        }
        $this->rule();

        foreach ($this->items as $item) {
            $this->bold(true)->wrap($item->quantity.'x '.$item->displayName())->bold(false);
            foreach ($item->modifiers as $modifier) {
                $this->wrap('   + '.$modifier->name, '     ');
            }
            if ($item->notes) {
                $this->wrap('   ! '.$item->notes, '     ');
            }
        }

        if ($order->notes && $this->batch === 1) {
            $this->rule()->wrap('Catatan: '.$order->notes);
        }

        $this->feed(3)->push('cut', null);
    }
}
