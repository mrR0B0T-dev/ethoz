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
        'Nama', 'Jabatan', 'Unit (kode)', 'Status', 'Gaji Dasar /bln',
        'Tunj. Jabatan /bln', 'Tunj. Transport /bln', 'TMT (YYYY-MM-DD)', 'Catatan',
    ];

    private const MAX_ROWS = 300;

    /**
     * @param  \Illuminate\Support\Collection<int, WorkUnit>  $units
     * @param  \Illuminate\Support\Collection<int, \App\Models\HcRkap\SalaryGrade>  $grades
     */
    public function buildTemplate($units, $grades = null): Spreadsheet
    {
        $grades ??= collect();
        $spreadsheet = new Spreadsheet;

        // ── Sheet Pegawai (diisi pengguna) sebagai sheet pertama ────────────
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pegawai');

        // ── Sheet Referensi (seluruh acuan input pegawai) ────────────────────
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

        // daftar jabatan referensi (unik, urut level) → dropdown kolom Jabatan
        $jabatanList = $grades->sortBy('level')->pluck('jabatan')->unique()->values();
        $ref->setCellValue('F1', 'Jabatan (referensi)');
        $ref->getStyle('F1')->getFont()->setBold(true);
        foreach ($jabatanList as $i => $j) {
            $ref->setCellValue('F'.($i + 2), $j);
        }
        $lastJabatanRow = max(2, $jabatanList->count() + 1);
        $ref->getColumnDimension('F')->setWidth(22);

        // struktur grade & skala upah: acuan Gaji Dasar (min–max) dan tarif tunjangan
        $gradeHeaders = [
            'H' => 'Grade', 'I' => 'Level', 'J' => 'Jabatan',
            'K' => 'Gaji Dasar Min', 'L' => 'Gaji Dasar Mid', 'M' => 'Gaji Dasar Max',
            'N' => 'Tunj. Jabatan /bln', 'O' => 'Tunj. Transport /bln',
        ];
        foreach ($gradeHeaders as $col => $label) {
            $ref->setCellValue("{$col}1", $label);
        }
        $ref->getStyle('H1:O1')->getFont()->setBold(true);
        $ref->getStyle('H1:O1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');
        $g = 2;
        foreach ($grades->sortBy('level') as $grade) {
            $ref->setCellValue("H{$g}", $grade->code);
            $ref->setCellValue("I{$g}", $grade->level);
            $ref->setCellValue("J{$g}", $grade->jabatan);
            $ref->setCellValue("K{$g}", $grade->salary_min);
            $ref->setCellValue("L{$g}", $grade->salary_mid);
            $ref->setCellValue("M{$g}", $grade->salary_max);
            $ref->setCellValue("N{$g}", $grade->position_allowance);
            $ref->setCellValue("O{$g}", $grade->transport_allowance);
            $g++;
        }
        $lastGradeRow = $g - 1;
        if ($lastGradeRow >= 2) {
            $ref->getStyle("K2:O{$lastGradeRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (['H' => 10, 'I' => 8, 'J' => 20, 'K' => 16, 'L' => 16, 'M' => 16, 'N' => 18, 'O' => 20] as $col => $w) {
            $ref->getColumnDimension($col)->setWidth($w);
        }

        // petunjuk pengisian di bawah tabel grade
        $notes = [
            'PETUNJUK PENGISIAN:',
            '• Jabatan, Unit & Status: pilih dari dropdown (daftar pada sheet ini).',
            '• Gaji Dasar: isi sesuai rentang skala upah grade pegawai (kolom Gaji Dasar Min–Max).',
            '• Tunj. Jabatan & Tunj. Transport: mengikuti tarif grade pada tabel di atas.',
            '• TMT: format YYYY-MM-DD (mis. 2026-01-15). Catatan: opsional.',
            '• Grade pegawai ditentukan otomatis oleh sistem dari gaji & tunjangan yang diisi.',
        ];
        $n = $lastGradeRow + 2;
        foreach ($notes as $i => $note) {
            $ref->setCellValue('H'.($n + $i), $note);
        }
        $ref->getStyle("H{$n}")->getFont()->setBold(true);
        $ref->getStyle("H{$n}:H".($n + count($notes) - 1))->getFont()->setSize(10);

        // ── Header & format sheet Pegawai ───────────────────────────────────
        foreach (self::HEADERS as $i => $label) {
            $sheet->setCellValue([$i + 1, 1], $label);
        }
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');
        $sheet->getStyle('A1:I1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        foreach (['A' => 26, 'B' => 24, 'C' => 12, 'D' => 12, 'E' => 16, 'F' => 18, 'G' => 18, 'H' => 18, 'I' => 30] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->getStyle('E2:G'.self::MAX_ROWS)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->freezePane('A2');

        // dropdown Jabatan (kolom B), Unit (kolom C) & Status (kolom D)
        if ($jabatanList->isNotEmpty()) {
            $this->applyListValidation($sheet, 'B', "=Referensi!\$F\$2:\$F\${$lastJabatanRow}", 'Pilih jabatan referensi dari daftar.');
        }
        $this->applyListValidation($sheet, 'C', "=Referensi!\$A\$2:\$A\${$lastUnitRow}", 'Pilih kode unit dari daftar.');
        $this->applyListValidation($sheet, 'D', '"'.implode(',', self::STATUSES).'"', 'Pilih status kepegawaian.');

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

            $jabatan = trim((string) $sheet->getCell([2, $row])->getValue()) ?: null;
            $unitCode = trim((string) $sheet->getCell([3, $row])->getValue());
            $status = mb_strtolower(trim((string) $sheet->getCell([4, $row])->getValue()));
            $base = $this->parseAmount($sheet->getCell([5, $row])->getValue());
            $position = $this->parseAmount($sheet->getCell([6, $row])->getValue()) ?? 0.0;
            $transport = $this->parseAmount($sheet->getCell([7, $row])->getValue()) ?? 0.0;
            $joinDate = $this->parseDate($sheet->getCell([8, $row])->getValue());
            $notes = trim((string) $sheet->getCell([9, $row])->getValue()) ?: null;

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
                'jabatan' => $jabatan ? mb_substr($jabatan, 0, 150) : null,
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
