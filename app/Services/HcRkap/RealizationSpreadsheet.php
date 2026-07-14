<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\FiscalYear;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

/**
 * Membangun template Excel input realisasi dan membaca kembali file
 * hasil isian untuk diimpor ke entri anggaran skenario "realisasi".
 */
class RealizationSpreadsheet
{
    public const MONTHS = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /** Baris pertama data bila baris header tidak ditemukan saat impor. */
    private const DEFAULT_DATA_START = 7;

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month - 1] ?? (string) $month;
    }

    /**
     * Template berisi seluruh kombinasi komponen × unit pada bulan terpilih
     * (baris yang sama dengan tabel Input Realisasi), dengan kolom Realisasi
     * yang bisa diisi. ID komponen/unit disimpan di kolom tersembunyi agar
     * pemetaan saat impor tidak bergantung pada nama.
     *
     * @param  array<int, array{cost_type_id:int, work_unit_id:int, category:?string, component:?string, unit:?string, rkap:float, realisasi:?float}>  $rows
     */
    public function buildTemplate(FiscalYear $year, int $month, array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Realisasi');

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'Template Import Realisasi — RKAP HC');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A2', 'Tahun');
        $sheet->setCellValue('B2', $year->year);
        $sheet->setCellValue('A3', 'Bulan');
        $sheet->setCellValue('B3', $month);
        $sheet->setCellValue('C3', self::monthName($month));
        $sheet->getStyle('A2:A3')->getFont()->setBold(true);

        $sheet->mergeCells('A4:F4');
        $sheet->setCellValue('A4', 'Isi hanya kolom "Realisasi (Rp)" dengan angka. Kosongkan bila belum ada realisasi. Jangan mengubah kolom lain.');
        $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(9);

        $headerRow = self::DEFAULT_DATA_START - 1;
        $headers = ['No', 'Kategori', 'Komponen Biaya', 'Unit', 'RKAP (Rp)', 'Realisasi (Rp)', 'CT_ID', 'WU_ID'];
        foreach ($headers as $i => $label) {
            $sheet->setCellValue([$i + 1, $headerRow], $label);
        }
        $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');

        $r = self::DEFAULT_DATA_START;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue([1, $r], $i + 1);
            $sheet->setCellValue([2, $r], $row['category']);
            $sheet->setCellValue([3, $r], $row['component']);
            $sheet->setCellValue([4, $r], $row['unit']);
            $sheet->setCellValue([5, $r], $row['rkap']);
            if ($row['realisasi'] !== null) {
                $sheet->setCellValue([6, $r], $row['realisasi']);
            }
            $sheet->setCellValue([7, $r], $row['cost_type_id']);
            $sheet->setCellValue([8, $r], $row['work_unit_id']);
            $r++;
        }
        $lastRow = $r - 1;

        foreach (['A' => 6, 'B' => 28, 'C' => 40, 'D' => 12, 'E' => 18, 'F' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getColumnDimension('G')->setVisible(false);
        $sheet->getColumnDimension('H')->setVisible(false);

        if ($lastRow >= self::DEFAULT_DATA_START) {
            $sheet->getStyle('E'.self::DEFAULT_DATA_START.":F{$lastRow}")
                ->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle('A1:H'.max($lastRow, $headerRow))->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A'.self::DEFAULT_DATA_START);

        // Kunci seluruh sheet kecuali kolom Realisasi agar struktur tidak
        // berubah tanpa sengaja (tanpa password; bisa dibuka dari Excel).
        $sheet->getProtection()->setSheet(true);
        if ($lastRow >= self::DEFAULT_DATA_START) {
            $sheet->getStyle('F'.self::DEFAULT_DATA_START.":F{$lastRow}")
                ->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        }

        return $spreadsheet;
    }

    /**
     * Baca file template yang sudah diisi.
     *
     * @return array{
     *     year: int,
     *     month: int,
     *     items: array<int, array{row:int, cost_type_id:int, work_unit_id:int, amount:float}>,
     *     errors: string[],
     * }
     */
    public function parseImport(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);

        $year = (int) $sheet->getCell('B2')->getValue();
        $month = (int) $sheet->getCell('B3')->getValue();

        $dataStart = $this->findDataStart($sheet);
        $lastRow = $sheet->getHighestDataRow();

        $items = [];
        $errors = [];
        for ($r = $dataStart; $r <= $lastRow; $r++) {
            $costTypeId = (int) $sheet->getCell([7, $r])->getValue();
            $workUnitId = (int) $sheet->getCell([8, $r])->getValue();
            if ($costTypeId <= 0 && $workUnitId <= 0) {
                continue; // baris kosong / bukan baris data
            }
            if ($costTypeId <= 0 || $workUnitId <= 0) {
                $errors[] = "Baris {$r}: kolom ID tersembunyi rusak, baris dilewati.";
                continue;
            }

            try {
                $raw = $sheet->getCell([6, $r])->getCalculatedValue();
            } catch (\Throwable) {
                $raw = $sheet->getCell([6, $r])->getValue();
            }
            if ($raw === null || $raw === '') {
                continue; // belum diisi
            }

            $amount = $this->parseAmount($raw);
            if ($amount === null || $amount < 0) {
                $errors[] = "Baris {$r}: nilai realisasi \"{$raw}\" tidak valid, baris dilewati.";
                continue;
            }

            $items[] = [
                'row' => $r,
                'cost_type_id' => $costTypeId,
                'work_unit_id' => $workUnitId,
                'amount' => $amount,
            ];
        }

        return compact('year', 'month', 'items', 'errors');
    }

    /** Cari baris header (kolom A berisi "No"); data mulai satu baris di bawahnya. */
    private function findDataStart(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): int
    {
        for ($r = 1; $r <= 20; $r++) {
            if (trim((string) $sheet->getCell([1, $r])->getValue()) === 'No') {
                return $r + 1;
            }
        }

        return self::DEFAULT_DATA_START;
    }

    /**
     * Normalisasi nilai realisasi: angka langsung dipakai; teks dengan pemisah
     * ribuan gaya Indonesia (1.234.567,89) maupun internasional (1,234,567.89)
     * dikenali. Mengembalikan null bila tidak bisa dibaca sebagai angka.
     */
    private function parseAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = preg_replace('/[^\d,.\-]/', '', trim((string) $value));
        if ($s === '' || $s === '-') {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot) {
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace(',', '.', str_replace('.', '', $s)); // 1.234.567,89
            } else {
                $s = str_replace(',', '', $s); // 1,234,567.89
            }
        } elseif ($hasComma) {
            $parts = explode(',', $s);
            $s = count($parts) === 2 && strlen(end($parts)) <= 2
                ? str_replace(',', '.', $s)   // 1234,56 → desimal
                : str_replace(',', '', $s);   // 1,234,567 → ribuan
        } elseif ($hasDot) {
            $parts = explode('.', $s);
            if (! (count($parts) === 2 && strlen(end($parts)) <= 2)) {
                $s = str_replace('.', '', $s); // 1.234.567 → ribuan
            }
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
