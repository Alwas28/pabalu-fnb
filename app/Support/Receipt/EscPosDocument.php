<?php

namespace App\Support\Receipt;

use Illuminate\Support\Str;

/**
 * Dokumen untuk printer thermal ESC/POS (58 mm = 32 kolom, 80 mm = 48 kolom).
 *
 * Turunan menyusun baris lewat helper (line, pair, wrap, rule, qr, …); kelas ini menghasilkan
 * byte perintah ESC/POS mentah — dikirim apa adanya oleh aplikasi kasir (Bluetooth / USB / LAN) —
 * serta versi teks polos untuk pratinjau.
 */
abstract class EscPosDocument
{
    protected const ESC = "\x1B";

    protected const GS = "\x1D";

    /** @var list<array{0: string, 1: mixed}> */
    protected array $commands = [];

    protected int $columns = 32;

    protected function setPaperWidth(int $paperWidth): void
    {
        $this->columns = $paperWidth >= 80 ? 48 : 32;
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

    /* ─────────────── Penyusun baris ─────────────── */

    protected function push(string $type, mixed $value): static
    {
        $this->commands[] = [$type, $value];

        return $this;
    }

    protected function align(string $align): static
    {
        return $this->push('align', $align);
    }

    protected function bold(bool $on): static
    {
        return $this->push('bold', $on);
    }

    protected function size(bool $double): static
    {
        return $this->push('size', $double);
    }

    protected function feed(int $lines): static
    {
        return $this->push('feed', $lines);
    }

    protected function qr(string $data): static
    {
        return $this->push('qr', $data);
    }

    protected function line(string $text): static
    {
        return $this->push('text', Str::limit($this->ascii($text), $this->columns, ''));
    }

    /** Teks panjang dipecah per kata sesuai lebar kertas. */
    protected function wrap(string $text, string $indent = ''): static
    {
        $wrapped = wordwrap($this->ascii($text), $this->columns, "\n".$indent, true);

        foreach (explode("\n", $wrapped) as $line) {
            $this->push('text', $line);
        }

        return $this;
    }

    /** Label kiri, nilai rata kanan dalam satu baris. */
    protected function pair(string $label, string $value): static
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

    protected function rule(): static
    {
        return $this->push('text', str_repeat('-', $this->columns));
    }

    protected function rate(mixed $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, ',', ''), '0'), ',').'%';
    }

    /** Printer thermal umumnya hanya mendukung ASCII; huruf beraksen ditransliterasi. */
    protected function ascii(string $text): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', Str::ascii($text));
    }

    /** Perintah QR bawaan printer: model 2, ukuran modul, koreksi galat M, simpan, cetak. */
    protected function qrBytes(string $data): string
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
