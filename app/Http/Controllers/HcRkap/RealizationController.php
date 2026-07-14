<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use App\Models\HcRkap\WorkUnit;
use App\Services\HcRkap\BudgetService;
use App\Services\HcRkap\RealizationSpreadsheet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RealizationController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(
        private BudgetService $budget,
        private RealizationSpreadsheet $spreadsheet,
    ) {}

    public function index(Request $request)
    {
        $year = $this->resolveYear($request);
        $month = min(12, max(1, $request->integer('bulan') ?: 1));

        return Inertia::render('HcRkap/Realisasi', [
            'tahun' => $year,
            'years' => $this->yearOptions(),
            'bulan' => $month,
            'rows' => $this->inputRows($year->id, $month),
            'ytd' => $this->ytdSummary($year->id, $month),
            'canEdit' => $year->status !== 'final',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'exists:hc_fiscal_years,id'],
            'month' => ['required', 'integer', 'between:1,12'],
            'items' => ['required', 'array'],
            'items.*.cost_type_id' => ['required', 'exists:hc_cost_types,id'],
            'items.*.work_unit_id' => ['required', 'exists:hc_work_units,id'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $year = FiscalYear::findOrFail($data['fiscal_year_id']);
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        foreach ($data['items'] as $item) {
            if ($item['amount'] === null || $item['amount'] === '') {
                continue;
            }
            BudgetEntry::updateOrCreate([
                'fiscal_year_id' => $year->id,
                'cost_type_id' => $item['cost_type_id'],
                'work_unit_id' => $item['work_unit_id'],
                'month' => $data['month'],
                'scenario' => 'realisasi',
            ], ['amount' => $item['amount']]);
        }

        return back()->with('success', 'Realisasi bulan '.$data['month'].' tersimpan.');
    }

    /** Unduh template Excel input realisasi untuk tahun & bulan terpilih. */
    public function template(Request $request)
    {
        $year = $this->resolveYear($request);
        $month = min(12, max(1, $request->integer('bulan') ?: 1));

        $workbook = $this->spreadsheet->buildTemplate($year, $month, $this->inputRows($year->id, $month));
        $filename = sprintf('Template_Realisasi_%d_%02d.xlsx', $year->year, $month);

        return response()->streamDownload(function () use ($workbook) {
            (new Xlsx($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Impor realisasi dari template Excel yang sudah diisi. */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [], ['file' => 'file Excel']);

        try {
            $parsed = $this->spreadsheet->parseImport($request->file('file')->getRealPath());
        } catch (\Throwable) {
            return back()->with('error', 'File tidak dapat dibaca. Gunakan template yang diunduh dari sistem.');
        }

        $year = FiscalYear::where('year', $parsed['year'])->first();
        if (! $year || $parsed['month'] < 1 || $parsed['month'] > 12) {
            return back()->with('error', 'Informasi tahun/bulan pada file tidak dikenal. Gunakan template yang diunduh dari sistem.');
        }
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        $validTypes = CostType::pluck('id')->flip();
        $validUnits = WorkUnit::pluck('id')->flip();

        $errors = $parsed['errors'];
        $saved = 0;
        foreach ($parsed['items'] as $item) {
            if (! $validTypes->has($item['cost_type_id']) || ! $validUnits->has($item['work_unit_id'])) {
                $errors[] = "Baris {$item['row']}: komponen biaya/unit tidak dikenal, baris dilewati.";
                continue;
            }
            BudgetEntry::updateOrCreate([
                'fiscal_year_id' => $year->id,
                'cost_type_id' => $item['cost_type_id'],
                'work_unit_id' => $item['work_unit_id'],
                'month' => $parsed['month'],
                'scenario' => 'realisasi',
            ], ['amount' => $item['amount']]);
            $saved++;
        }

        $redirect = redirect()->route('hc.realisasi', [
            'tahun' => $year->year,
            'bulan' => $parsed['month'],
        ]);

        $label = RealizationSpreadsheet::monthName($parsed['month']).' '.$year->year;
        if ($saved === 0) {
            return $redirect->with('error', $errors
                ? "Tidak ada baris yang tersimpan. {$errors[0]}"
                : "Tidak ada nilai realisasi yang terisi pada file ({$label}).");
        }

        $message = "Import realisasi {$label}: {$saved} baris tersimpan.";
        if ($errors) {
            $message .= ' '.count($errors).' baris dilewati — '.$errors[0];
        }

        return $redirect->with('success', $message);
    }

    /**
     * Baris input realisasi: setiap kombinasi komponen × unit yang punya
     * RKAP pada bulan terpilih, beserta realisasi yang sudah tercatat.
     */
    private function inputRows(int $yearId, int $month): array
    {
        $rows = $this->budget->aggregate($yearId, [
            'month_start' => $month,
            'month_end' => $month,
        ]);

        $types = $this->budget->costTypes()->keyBy('id');
        $units = $this->budget->units()->keyBy('id');

        $grouped = [];
        foreach ($rows as $r) {
            $key = $r->cost_type_id.'-'.$r->work_unit_id;
            $type = $types->get($r->cost_type_id);
            $grouped[$key] ??= [
                'cost_type_id' => $r->cost_type_id,
                'work_unit_id' => $r->work_unit_id,
                'category' => $type?->parent_id
                    ? $types->get($type->parent_id)?->name
                    : $type?->name,
                'category_sort' => $type?->parent_id
                    ? $types->get($type->parent_id)?->sort_order
                    : $type?->sort_order,
                'component' => $type?->name,
                'component_sort' => $type?->sort_order,
                'unit' => $units->get($r->work_unit_id)?->code,
                'rkap' => 0.0,
                'realisasi' => null,
            ];
            if ($r->scenario === 'rkap') {
                $grouped[$key]['rkap'] += (float) $r->amount;
            } elseif ($r->scenario === 'realisasi') {
                $grouped[$key]['realisasi'] = (float) $r->amount;
            }
        }

        return collect($grouped)
            ->filter(fn ($g) => $g['rkap'] != 0 || $g['realisasi'] !== null)
            ->sortBy([['category_sort', 'asc'], ['component_sort', 'asc'], ['rkap', 'desc']])
            ->map(fn ($g) => collect($g)->except(['category_sort', 'component_sort'])->all())
            ->values()->all();
    }

    /** Ringkasan kumulatif Januari s.d. bulan terpilih per kategori. */
    private function ytdSummary(int $yearId, int $month): array
    {
        $rows = $this->budget->aggregate($yearId, [
            'month_start' => 1,
            'month_end' => $month,
        ]);

        $types = $this->budget->costTypes();
        $roots = $types->whereNull('parent_id')->sortBy('sort_order');

        return $roots->map(function ($root) use ($rows) {
            $ids = $this->budget->costTypeDescendantIds($root->id);
            $set = $rows->whereIn('cost_type_id', $ids);
            $rkap = (float) $set->where('scenario', 'rkap')->sum('amount');
            $realisasi = (float) $set->where('scenario', 'realisasi')->sum('amount');

            return [
                'name' => $root->name,
                'rkap' => $rkap,
                'realisasi' => $realisasi,
                'selisih' => $rkap - $realisasi,
                'serapan' => $rkap > 0 ? round($realisasi / $rkap * 100, 1) : null,
            ];
        })->filter(fn ($r) => $r['rkap'] != 0 || $r['realisasi'] != 0)->values()->all();
    }
}
