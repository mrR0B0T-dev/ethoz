<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Template Excel "Input Nominal": matriks unit kerja × 12 bulan untuk satu
 * jenis biaya (yang bisa diinput manual). Nilai yang sudah ada ikut terisi,
 * kolom bulan bisa diedit, lalu diimpor kembali sebagai nominal RKAP.
 */
class NominalSpreadsheet
{
    public const MONTHS = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    private const HEADER_ROW = 6;

    private const DATA_START = 7;

    /** Kolom nilai bulan: D (4) … O (15); kolom P (16) = WU_ID tersembunyi. */
    private const MONTH_COL_START = 4;

    private const WU_ID_COL = 16;

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month - 1] ?? (string) $month;
    }

    /**
     * @param  array<int, array{work_unit_id:int, code:?string, name:?string, months:array}>  $unitRows  nilai yang sudah ada
     * @param  \Illuminate\Support\Collection  $units  seluruh unit aktif (baris template)
     */
    public function buildTemplate(FiscalYear $year, CostType $type, array $unitRows, $units): Spreadsheet
    {
        $existing = [];
        foreach ($unitRows as $r) {
            $existing[$r['work_unit_id']] = $r['months'];
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nominal');

        $sheet->setCellValue('A1', 'Template Import Nominal RKAP — '.$type->name);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A2', 'Tahun');
        $sheet->setCellValue('B2', $year->year);
        $sheet->setCellValue('A3', 'Komponen');
        $sheet->setCellValue('B3', $type->name);
        $sheet->setCellValue('C3', $type->code);
        $sheet->setCellValue('A4', 'ID Komponen');
        $sheet->setCellValue('B4', $type->id);
        $sheet->getStyle('A2:A4')->getFont()->setBold(true);
        $sheet->getRowDimension(4)->setVisible(false); // baris ID komponen disembunyikan

        $sheet->setCellValue('A5', 'Isi nilai nominal RKAP (Rupiah) tiap unit × bulan. Kosongkan sel untuk menghapus nilainya. Jangan mengubah kolom Kode/Nama/ID.');
        $sheet->getStyle('A5')->getFont()->setItalic(true)->setSize(9);

        // ── Header tabel ────────────────────────────────────────────────────
        $hr = self::HEADER_ROW;
        $sheet->setCellValue([1, $hr], 'No');
        $sheet->setCellValue([2, $hr], 'Kode Unit');
        $sheet->setCellValue([3, $hr], 'Nama Unit');
        foreach (self::MONTHS as $i => $m) {
            $sheet->setCellValue([self::MONTH_COL_START + $i, $hr], $m);
        }
        $sheet->setCellValue([self::WU_ID_COL, $hr], 'WU_ID');
        $sheet->getStyle("A{$hr}:P{$hr}")->getFont()->setBold(true);
        $sheet->getStyle("A{$hr}:P{$hr}")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');
        $sheet->getStyle("A{$hr}:P{$hr}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // ── Baris data (seluruh unit aktif) ─────────────────────────────────
        $row = self::DATA_START;
        $no = 1;
        foreach ($units as $unit) {
            $sheet->setCellValue([1, $row], $no);
            $sheet->setCellValue([2, $row], $unit->code);
            $sheet->setCellValue([3, $row], $unit->name);
            $months = $existing[$unit->id] ?? array_fill(0, 12, null);
            foreach ($months as $mi => $val) {
                if ($val !== null && $val !== '') {
                    $sheet->setCellValue([self::MONTH_COL_START + $mi, $row], $val);
                }
            }
            $sheet->setCellValue([self::WU_ID_COL, $row], $unit->id);
            $row++;
            $no++;
        }
        $lastRow = $row - 1;

        // ── Format & proteksi ───────────────────────────────────────────────
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(32);
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(13);
        }
        $sheet->getColumnDimension('P')->setVisible(false);

        if ($lastRow >= self::DATA_START) {
            $valueRange = 'D'.self::DATA_START.":O{$lastRow}";
            $sheet->getStyle($valueRange)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle($valueRange)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        }
        $sheet->freezePane('D'.self::DATA_START);

        // Kunci struktur (kode/nama/ID) — hanya kolom bulan yang bisa diedit.
        $sheet->getProtection()->setSheet(true);

        return $spreadsheet;
    }

    /**
     * @return array{year:int, costTypeId:int, items: array<int, array{row:int, work_unit_id:int, months: array<int, ?float>}>, errors: string[]}
     */
    public function parseImport(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);

        $year = (int) $sheet->getCell('B2')->getValue();
        $costTypeId = (int) $sheet->getCell('B4')->getValue();

        $dataStart = $this->findDataStart($sheet);
        $lastRow = $sheet->getHighestDataRow();

        $items = [];
        $errors = [];
        for ($r = $dataStart; $r <= $lastRow; $r++) {
            $workUnitId = (int) $sheet->getCell([self::WU_ID_COL, $r])->getValue();
            if ($workUnitId <= 0) {
                continue; // baris kosong / bukan baris data
            }

            $months = [];
            for ($m = 0; $m < 12; $m++) {
                $raw = $sheet->getCell([self::MONTH_COL_START + $m, $r])->getValue();
                if ($raw === null || $raw === '') {
                    $months[] = null;

                    continue;
                }
                $amount = $this->parseAmount($raw);
                if ($amount === null || $amount < 0) {
                    $errors[] = "Baris {$r}, bulan ".self::monthName($m + 1).": nilai \"{$this->clip($raw)}\" tidak valid, dikosongkan.";
                    $months[] = null;

                    continue;
                }
                $months[] = $amount;
            }

            $items[] = ['row' => $r, 'work_unit_id' => $workUnitId, 'months' => $months];
        }

        return compact('year', 'costTypeId', 'items', 'errors');
    }

    private function findDataStart(Worksheet $sheet): int
    {
        for ($r = 1; $r <= 20; $r++) {
            if (trim((string) $sheet->getCell([1, $r])->getValue()) === 'No') {
                return $r + 1;
            }
        }

        return self::DATA_START;
    }

    private function clip(mixed $value): string
    {
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        return mb_strlen($s) > 24 ? mb_substr($s, 0, 24).'…' : $s;
    }

    /** Angka langsung; teks berformat ribuan ID/internasional dinormalisasi. */
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
            $s = strrpos($s, ',') > strrpos($s, '.')
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        } elseif ($hasComma) {
            $parts = explode(',', $s);
            $s = count($parts) === 2 && strlen(end($parts)) <= 2
                ? str_replace(',', '.', $s)
                : str_replace(',', '', $s);
        } elseif ($hasDot) {
            $parts = explode('.', $s);
            if (! (count($parts) === 2 && strlen(end($parts)) <= 2)) {
                $s = str_replace('.', '', $s);
            }
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
