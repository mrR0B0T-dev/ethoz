<?php

namespace App\Services\HcRkap;

use App\Models\HcRkap\WorkUnit;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Template Excel "Tambah Pegawai": pengguna mengisi daftar pegawai baru,
 * lalu diimpor ke tabel pegawai. Kolom Unit & Status memakai dropdown yang
 * merujuk daftar valid di sheet "Referensi".
 */
class EmployeeSpreadsheet
{
    public const STATUSES = ['tetap', 'kontrak', 'honor', 'direksi'];

    /** Header kolom data (baris 1). */
    private const HEADERS = [
        'Nama', 'Unit (kode)', 'Status', 'Gaji Dasar /bln',
        'Tunj. Jabatan /bln', 'Tunj. Transport /bln', 'TMT (YYYY-MM-DD)', 'Catatan',
    ];

    private const MAX_ROWS = 300;

    /**
     * @param  \Illuminate\Support\Collection<int, WorkUnit>  $units
     */
    public function buildTemplate($units): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;

        // ── Sheet Pegawai (diisi pengguna) sebagai sheet pertama ────────────
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pegawai');

        // ── Sheet Referensi (daftar unit & status) ──────────────────────────
        $ref = $spreadsheet->createSheet();
        $ref->setTitle('Referensi');
        $ref->setCellValue('A1', 'Kode Unit');
        $ref->setCellValue('B1', 'Nama Unit');
        $ref->getStyle('A1:B1')->getFont()->setBold(true);
        $r = 2;
        foreach ($units as $u) {
            $ref->setCellValue("A{$r}", $u->code);
            $ref->setCellValue("B{$r}", $u->name);
            $r++;
        }
        $lastUnitRow = $r - 1;
        $ref->setCellValue('D1', 'Status');
        $ref->getStyle('D1')->getFont()->setBold(true);
        foreach (self::STATUSES as $i => $s) {
            $ref->setCellValue('D'.($i + 2), $s);
        }
        $ref->getColumnDimension('A')->setWidth(16);
        $ref->getColumnDimension('B')->setWidth(38);
        $ref->getColumnDimension('D')->setWidth(14);

        // ── Header & format sheet Pegawai ───────────────────────────────────
        foreach (self::HEADERS as $i => $label) {
            $sheet->setCellValue([$i + 1, 1], $label);
        }
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');
        $sheet->getStyle('A1:H1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        foreach (['A' => 26, 'B' => 12, 'C' => 12, 'D' => 16, 'E' => 18, 'F' => 18, 'G' => 18, 'H' => 30] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->getStyle('D2:F'.self::MAX_ROWS)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->freezePane('A2');

        // dropdown Unit (kolom B) & Status (kolom C)
        $this->applyListValidation($sheet, 'B', "=Referensi!\$A\$2:\$A\${$lastUnitRow}", 'Pilih kode unit dari daftar.');
        $this->applyListValidation($sheet, 'C', '"'.implode(',', self::STATUSES).'"', 'Pilih status kepegawaian.');

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function applyListValidation($sheet, string $col, string $formula, string $prompt): void
    {
        for ($row = 2; $row <= self::MAX_ROWS; $row++) {
            $dv = $sheet->getCell("{$col}{$row}")->getDataValidation();
            $dv->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowInputMessage(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Input tidak valid')
                ->setError('Nilai tidak ada dalam daftar.')
                ->setPromptTitle('Pilih dari daftar')
                ->setPrompt($prompt)
                ->setFormula1($formula);
        }
    }

    /**
     * Baca template terisi menjadi baris pegawai siap-simpan.
     *
     * @return array{
     *     items: array<int, array{row:int, name:string, work_unit_id:?int, status:string,
     *         base_salary:float, position_allowance:float, transport_allowance:float,
     *         join_date:?string, notes:?string}>,
     *     errors: string[],
     * }
     */
    public function parseImport(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName('Pegawai') ?? $spreadsheet->getSheet(0);

        $unitIdByCode = WorkUnit::pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => $id]);

        $items = [];
        $errors = [];
        $lastRow = $sheet->getHighestDataRow();

        for ($row = 2; $row <= $lastRow; $row++) {
            $name = trim((string) $sheet->getCell([1, $row])->getValue());
            if ($name === '') {
                continue; // baris kosong dilewati
            }

            $unitCode = trim((string) $sheet->getCell([2, $row])->getValue());
            $status = mb_strtolower(trim((string) $sheet->getCell([3, $row])->getValue()));
            $base = $this->parseAmount($sheet->getCell([4, $row])->getValue());
            $position = $this->parseAmount($sheet->getCell([5, $row])->getValue()) ?? 0.0;
            $transport = $this->parseAmount($sheet->getCell([6, $row])->getValue()) ?? 0.0;
            $joinDate = $this->parseDate($sheet->getCell([7, $row])->getValue());
            $notes = trim((string) $sheet->getCell([8, $row])->getValue()) ?: null;

            if (! in_array($status, self::STATUSES, true)) {
                $errors[] = "Baris {$row} ({$name}): status \"{$status}\" tidak valid, dilewati.";
                continue;
            }

            $workUnitId = null;
            if ($unitCode !== '') {
                $key = mb_strtoupper($unitCode);
                if (! $unitIdByCode->has($key)) {
                    $errors[] = "Baris {$row} ({$name}): kode unit \"{$unitCode}\" tidak dikenal, dilewati.";
                    continue;
                }
                $workUnitId = $unitIdByCode->get($key);
            }

            if ($base === null || $base < 0) {
                $errors[] = "Baris {$row} ({$name}): Gaji Dasar tidak valid, dilewati.";
                continue;
            }

            $items[] = [
                'row' => $row,
                'name' => mb_substr($name, 0, 150),
                'work_unit_id' => $workUnitId,
                'status' => $status,
                'base_salary' => $base,
                'position_allowance' => max(0.0, $position),
                'transport_allowance' => max(0.0, $transport),
                'join_date' => $joinDate,
                'notes' => $notes ? mb_substr($notes, 0, 1000) : null,
            ];
        }

        return compact('items', 'errors');
    }

    /** Angka langsung; teks berformat ribuan ID/internasional dinormalisasi. */
    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
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

    /** Tanggal Excel (serial) atau teks (YYYY-MM-DD / dd/mm/yyyy) → Y-m-d. */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null; // tanggal tak terbaca → dikosongkan (bukan kesalahan fatal)
        }
    }
}
