<?php

namespace App\Support\Reports\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penulis workbook Excel bergaya seragam untuk laporan Pabalu: judul, info laporan, tabel dengan
 * header berwarna, format rupiah, baris total, header kolom yang terkunci saat digulir.
 *
 * Tipe kolom: text, number (jumlah bahan), int, money (rupiah), percent.
 */
class WorkbookWriter
{
    /** Batas baris rincian per sheet agar file & memori tetap wajar. */
    public const MAX_DETAIL_ROWS = 20000;

    private const BRAND = 'F59E0B';

    private const HEADER_FILL = 'FEF3C7';

    private const FORMATS = [
        'money' => '"Rp" #,##0;[Red]-"Rp" #,##0',
        'number' => '#,##0.###;[Red]-#,##0.###',
        'int' => '#,##0;[Red]-#,##0',
        'percent' => '0.0"%"',
    ];

    private Spreadsheet $book;

    private bool $firstSheet = true;

    public function __construct(string $title, string $company)
    {
        $this->book = new Spreadsheet;
        $this->book->getProperties()->setCreator('Pabalu')->setCompany($company)->setTitle($title);
        $this->book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
    }

    public function sheet(string $title): Worksheet
    {
        $sheet = $this->firstSheet ? $this->book->getActiveSheet() : $this->book->createSheet();
        $this->firstSheet = false;

        return $sheet->setTitle(mb_substr($title, 0, 31));
    }

    /**
     * Judul laporan + baris info (mis. periode, outlet). Mengembalikan baris kosong berikutnya.
     *
     * @param  list<string>  $info
     */
    public function title(Worksheet $sheet, string $title, array $info): int
    {
        // Sel judul digabung agar tidak ikut melebarkan kolom A saat lebar kolom diatur otomatis.
        $sheet->setCellValue('A1', $title)->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('B45309');

        foreach ($info as $index => $line) {
            $row = $index + 2;
            $sheet->setCellValue("A{$row}", $line)->mergeCells("A{$row}:H{$row}");
            $sheet->getStyle("A{$row}")->getFont()->getColor()->setRGB('6B7280');
        }

        return count($info) + 3;
    }

    /**
     * Tabel lengkap dengan header, isi, dan baris total opsional. Mengembalikan baris kosong berikutnya.
     *
     * @param  list<array{0: string, 1: string}>  $columns  [label, tipe]
     * @param  iterable<list<mixed>>  $rows
     * @param  list<mixed>|null  $totals  baris total (null = tanpa total)
     */
    public function table(Worksheet $sheet, int $row, array $columns, iterable $rows, ?array $totals = null, ?string $heading = null, bool $freeze = false): int
    {
        if ($heading !== null) {
            $sheet->setCellValue("A{$row}", $heading);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
            $row++;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $headerRow = $row;

        foreach ($columns as $index => [$label]) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$row, $label);
        }
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '78350F']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL]],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BRAND]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        if ($freeze) {
            $sheet->freezePane('A'.($headerRow + 1));
        }

        $first = $row + 1;
        foreach ($rows as $values) {
            $row++;
            $sheet->fromArray(array_values($values), null, "A{$row}", true);
        }

        if ($row === $headerRow) {
            $row++;
            $sheet->setCellValue("A{$row}", 'Tidak ada data pada periode ini.');
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB('9CA3AF');
        }

        $last = $row;

        if ($totals !== null) {
            $row++;
            $sheet->fromArray(array_values($totals), null, "A{$row}", true);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '9CA3AF']]],
            ]);
        }

        foreach ($columns as $index => [, $type]) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            if ($type === 'number') {
                // Bilangan bulat tanpa desimal; pecahan (mis. 0,5 kg) dengan desimal — hindari "20.000," di Excel.
                for ($r = $first; $r <= $row; $r++) {
                    $value = $sheet->getCell("{$letter}{$r}")->getValue();
                    if (is_int($value) || is_float($value)) {
                        $format = floor($value) == $value ? self::FORMATS['int'] : self::FORMATS['number'];
                        $sheet->getStyle("{$letter}{$r}")->getNumberFormat()->setFormatCode($format);
                    }
                }
            } elseif (isset(self::FORMATS[$type])) {
                $sheet->getStyle("{$letter}{$first}:{$letter}{$row}")->getNumberFormat()->setFormatCode(self::FORMATS[$type]);
            }
        }

        $this->autosize($sheet, count($columns));

        return $row + ($last >= $first ? 2 : 1);
    }

    /**
     * Daftar label → nilai (mis. laba rugi). Baris dengan `bold` dicetak tebal bergaris atas.
     *
     * @param  list<array{0: string, 1: int|float|string|null, 2?: string, 3?: bool}>  $lines  [label, nilai, tipe, tebal]
     */
    public function keyValues(Worksheet $sheet, int $row, string $heading, array $lines): int
    {
        $sheet->setCellValue("A{$row}", $heading);
        $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '78350F']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL]],
        ]);

        foreach ($lines as $line) {
            $row++;
            [$label, $value] = $line;
            $type = $line[2] ?? 'money';
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);

            if (isset(self::FORMATS[$type])) {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::FORMATS[$type]);
            }
            if ($line[3] ?? false) {
                $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '9CA3AF']]],
                ]);
            }
        }

        $this->autosize($sheet, 2);

        return $row + 2;
    }

    /** Catatan kecil abu-abu (mis. definisi angka). */
    public function note(Worksheet $sheet, int $row, string $text): int
    {
        $sheet->setCellValue("A{$row}", $text)->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('6B7280');

        return $row + 1;
    }

    public function download(string $filename): StreamedResponse
    {
        $this->book->setActiveSheetIndex(0);
        $book = $this->book;

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function spreadsheet(): Spreadsheet
    {
        return $this->book;
    }

    private function autosize(Worksheet $sheet, int $columns): void
    {
        for ($i = 1; $i <= $columns; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }
}
