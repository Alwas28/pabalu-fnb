<?php

namespace App\Support\Receipt;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\Money;

/**
 * Struk untuk printer thermal ESC/POS (58 mm = 32 kolom, 80 mm = 48 kolom).
 *
 * Menghasilkan byte perintah ESC/POS mentah — dikirim apa adanya oleh aplikasi kasir
 * (Bluetooth / USB / LAN) — serta versi teks polos untuk pratinjau.
 * QR code dicetak dengan perintah QR bawaan printer (GS ( k) dan mengarah ke struk web.
 */
class EscPosReceipt extends EscPosDocument
{
    public function __construct(private Order $order, ?int $paperWidth = null)
    {
        $order->loadReceipt();
        $this->setPaperWidth($paperWidth ?? $order->outlet->receipt_paper_width);
        $this->compose();
    }

    private function compose(): void
    {
        $order = $this->order;
        $outlet = $order->outlet;
        $business = $order->business;
        $time = $order->placed_at->timezone($business->timezone ?: config('app.timezone'));

        // ── Kepala struk
        $this->align('center')->bold(true)->size(true)->wrap($business->name)->size(false)->bold(false);
        $this->wrap($outlet->name);
        if ($outlet->address) {
            $this->wrap($outlet->address);
        }
        if ($outlet->phone) {
            $this->line('Telp. '.$outlet->phone);
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            $this->feed(1)->bold(true)->line($order->status === OrderStatus::Cancelled ? '*** DIBATALKAN ***' : '*** TAGIHAN - BELUM LUNAS ***')->bold(false);
        }

        $this->align('left')->rule();
        $this->pair('No', $order->number);
        $this->pair('Waktu', $time->format('d/m/Y H:i'));
        $this->pair('Kasir', (string) $order->cashier?->name);
        $this->pair('Layanan', $order->service_type->label().($order->table_number ? ' - Meja '.$order->table_number : ''));
        if ($order->customer_name) {
            $this->pair('Pelanggan', $order->customer_name);
        }
        $this->rule();

        // ── Item
        foreach ($order->items as $item) {
            $this->wrap($item->displayName());
            $this->pair('  '.$item->quantity.' x '.Money::plain($item->unit_price), Money::plain($item->line_total));
            foreach ($item->modifiers as $modifier) {
                $this->wrap('  + '.$modifier->name, '    ');
            }
            if ($item->notes) {
                $this->wrap('  * '.$item->notes, '    ');
            }
        }
        $this->rule();

        // ── Total
        $this->pair('Subtotal', Money::plain($order->subtotal));
        if ($order->discount_amount > 0) {
            $label = $order->discount_type === DiscountType::Percent ? 'Diskon '.$this->rate($order->discount_value) : 'Diskon';
            $this->pair($label, '-'.Money::plain($order->discount_amount));
        }
        if ($order->service_charge_amount > 0) {
            $this->pair('Service '.$this->rate($order->service_charge_rate), Money::plain($order->service_charge_amount));
        }
        if ($order->tax_amount > 0 && ! $order->prices_include_tax) {
            $this->pair('Pajak '.$this->rate($order->tax_rate), Money::plain($order->tax_amount));
        }
        $this->bold(true)->pair('TOTAL', Money::format($order->total))->bold(false);
        if ($order->tax_amount > 0 && $order->prices_include_tax) {
            $this->pair('Termasuk pajak '.$this->rate($order->tax_rate), Money::plain($order->tax_amount));
        }
        $this->rule();

        // ── Pembayaran
        if ($order->payments->isEmpty()) {
            $this->line('Belum dibayar');
        }
        foreach ($order->payments as $payment) {
            $this->pair($payment->method->label(), Money::plain($payment->tendered_amount));
            if ($payment->reference) {
                $this->line('  Ref: '.$payment->reference);
            }
        }
        if ($order->change_amount > 0) {
            $this->bold(true)->pair('Kembalian', Money::plain($order->change_amount))->bold(false);
        }

        // ── QR struk digital & penutup
        $this->feed(1)->align('center');
        $this->qr($order->receiptUrl());
        $this->line('Scan untuk struk digital');
        if ($outlet->receipt_footer) {
            $this->feed(1)->wrap($outlet->receipt_footer);
        } else {
            $this->feed(1)->line('Terima kasih atas kunjungan Anda');
        }
        $this->feed(3)->push('cut', null);
    }
}
