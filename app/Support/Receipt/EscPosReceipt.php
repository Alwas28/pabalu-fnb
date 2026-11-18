<?php

namespace App\Support\Receipt;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Struk untuk printer thermal ESC/POS (58 mm = 32 kolom, 80 mm = 48 kolom).
 *
 * Menghasilkan byte perintah ESC/POS mentah — dikirim apa adanya oleh aplikasi kasir
 * (Bluetooth / USB / LAN) — serta versi teks polos untuk pratinjau.
 * QR code dicetak dengan perintah QR bawaan printer (GS ( k) dan mengarah ke struk web.
 */
class EscPosReceipt
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    /** @var list<array{0: string, 1: mixed}> */
    private array $commands = [];

    private int $columns;

    public function __construct(private Order $order, ?int $paperWidth = null)
    {
        $order->loadReceipt();
        $this->columns = ($paperWidth ?? $order->outlet->receipt_paper_width) >= 80 ? 48 : 32;
        $this->compose();
    }

    public function columns(): int
    {
        return $this->columns;
    }

    /** Byte ESC/POS siap kirim ke printer. */
    public function bytes(): string
    {
        $out = self::ESC.'@'.self::ESC.'t'."\x00"; // inisialisasi + code page PC437

        foreach ($this->commands as [$type, $value]) {
            $out .= match ($type) {
                'align' => self::ESC.'a'.chr(['left' => 0, 'center' => 1, 'right' => 2][$value]),
                'bold' => self::ESC.'E'.chr($value ? 1 : 0),
                'size' => self::GS.'!'.chr($value ? 0x11 : 0x00),
                'text' => $value."\n",
                'feed' => self::ESC.'d'.chr($value),
                'qr' => $this->qrBytes($value),
                'cut' => self::GS.'V'.chr(66).chr(0),
            };
        }

        return $out;
    }

    /** Pratinjau teks polos (tanpa perintah printer). */
    public function text(): string
    {
        $lines = [];
        $align = 'left';

        foreach ($this->commands as [$type, $value]) {
            if ($type === 'align') {
                $align = $value;
            } elseif ($type === 'text') {
                $lines[] = match ($align) {
                    'center' => str_pad($value, $this->columns, ' ', STR_PAD_BOTH),
                    'right' => str_pad($value, $this->columns, ' ', STR_PAD_LEFT),
                    default => $value,
                };
            } elseif ($type === 'qr') {
                $lines[] = str_pad('[ QR CODE ]', $this->columns, ' ', STR_PAD_BOTH);
            }
        }

        return implode("\n", array_map('rtrim', $lines))."\n";
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

    /* ─────────────── Penyusun baris ─────────────── */

    private function push(string $type, mixed $value): static
    {
        $this->commands[] = [$type, $value];

        return $this;
    }

    private function align(string $align): static
    {
        return $this->push('align', $align);
    }

    private function bold(bool $on): static
    {
        return $this->push('bold', $on);
    }

    private function size(bool $double): static
    {
        return $this->push('size', $double);
    }

    private function feed(int $lines): static
    {
        return $this->push('feed', $lines);
    }

    private function qr(string $data): static
    {
        return $this->push('qr', $data);
    }

    private function line(string $text): static
    {
        return $this->push('text', Str::limit($this->ascii($text), $this->columns, ''));
    }

    /** Teks panjang dipecah per kata sesuai lebar kertas. */
    private function wrap(string $text, string $indent = ''): static
    {
        $wrapped = wordwrap($this->ascii($text), $this->columns, "\n".$indent, true);

        foreach (explode("\n", $wrapped) as $line) {
            $this->push('text', $line);
        }

        return $this;
    }

    /** Label kiri, nilai rata kanan dalam satu baris. */
    private function pair(string $label, string $value): static
    {
        $label = $this->ascii($label);
        $value = $this->ascii($value);
        $space = $this->columns - strlen($value) - 1;

        if (strlen($label) > $space) {
            $this->push('text', Str::limit($label, $this->columns, ''));
            $label = '';
        }

        return $this->push('text', str_pad($label, $this->columns - strlen($value)).$value);
    }

    private function rule(): static
    {
        return $this->push('text', str_repeat('-', $this->columns));
    }

    private function rate(mixed $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, ',', ''), '0'), ',').'%';
    }

    /** Printer thermal umumnya hanya mendukung ASCII; huruf beraksen ditransliterasi. */
    private function ascii(string $text): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', Str::ascii($text));
    }

    /** Perintah QR bawaan printer: model 2, ukuran modul, koreksi galat M, simpan, cetak. */
    private function qrBytes(string $data): string
    {
        $size = $this->columns >= 48 ? 7 : 5;
        $length = strlen($data) + 3;

        return self::GS."(k\x04\x00\x31\x41\x32\x00"
            .self::GS."(k\x03\x00\x31\x43".chr($size)
            .self::GS."(k\x03\x00\x31\x45\x31"
            .self::GS.'(k'.chr($length % 256).chr(intdiv($length, 256))."\x31\x50\x30".$data
            .self::GS."(k\x03\x00\x31\x51\x30"
            ."\n";
    }
}
