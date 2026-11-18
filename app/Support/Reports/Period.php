<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Rentang tanggal laporan dalam zona waktu bisnis. Batas waktu dikonversi ke UTC untuk query.
 */
final class Period
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    public static function make(?string $from, ?string $to, string $timezone): self
    {
        try {
            $start = $from ? CarbonImmutable::parse($from, $timezone) : CarbonImmutable::now($timezone)->startOfMonth();
            $end = $to ? CarbonImmutable::parse($to, $timezone) : CarbonImmutable::now($timezone);
        } catch (\Throwable) {
            $start = CarbonImmutable::now($timezone)->startOfMonth();
            $end = CarbonImmutable::now($timezone);
        }

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return new self($start->startOfDay(), $end->endOfDay(), $timezone);
    }

    /** @return array{0: Carbon, 1: Carbon} batas UTC untuk kolom timestamp */
    public function utcRange(): array
    {
        return [Carbon::instance($this->from->utc()), Carbon::instance($this->to->utc())];
    }

    /** @return array{0: string, 1: string} batas tanggal untuk kolom date */
    public function dateRange(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    public function label(): string
    {
        return $this->from->translatedFormat('d M Y').' – '.$this->to->translatedFormat('d M Y');
    }

    /** Selisih jam zona waktu bisnis terhadap UTC, untuk mengelompokkan per tanggal lokal di SQL. */
    public function offsetHours(): int
    {
        return intdiv($this->from->getOffset(), 3600);
    }
}
