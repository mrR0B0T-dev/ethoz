<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use App\Models\HcRkap\WorkUnit;
use App\Services\HcRkap\BudgetService;
use App\Services\HcRkap\NominalSpreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menu Input Nominal — tempat mengatur nilai RKAP per jenis biaya.
 * Jenis biaya bertanda "is_derived" nilainya mengacu ke jenis biaya lain
 * sehingga tidak dapat diinput manual di sini.
 */
class NominalController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(
        private BudgetService $budget,
        private NominalSpreadsheet $spreadsheet,
    ) {}

    public function index(Request $request)
    {
        $year = $this->resolveYear($request);
        $types = $this->budget->costTypes();

        // komponen yang bisa dipilih: seluruh jenis biaya yang punya induk
        $components = $types->whereNotNull('parent_id');
        $selectedId = $request->integer('komponen') ?: null;
        $selected = $selectedId ? $components->firstWhere('id', $selectedId) : null;
        $selected ??= $components->sortBy('sort_order')->first(fn ($t) => ! $t->is_derived);

        return Inertia::render('HcRkap/Nominal', [
            'tahun' => $year,
            'years' => $this->yearOptions(),
            'tree' => $types->whereNull('parent_id')->sortBy('sort_order')->values()
                ->map(fn ($root) => [
                    'id' => $root->id,
                    'name' => $root->name,
                    'components' => $types->where('parent_id', $root->id)->sortBy('sort_order')->values()
                        ->map(fn ($t) => $t->only('id', 'code', 'name', 'employee_status', 'is_derived', 'derived_note', 'employee_source')),
                ]),
            'selected' => $selected?->only('id', 'code', 'name', 'employee_status', 'is_derived', 'derived_note', 'employee_source'),
            'rows' => $selected ? $this->unitRows($year->id, $selected->id) : [],
            'options' => [
                'units' => $this->budget->units()->where('is_active', true)
                    ->map(fn ($u) => $u->only('id', 'code', 'name', 'type', 'parent_id'))->values(),
            ],
            'canEdit' => $year->status !== 'final' && $selected && ! $selected->is_derived,
        ]);
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'exists:hc_fiscal_years,id'],
            'cost_type_id' => ['required', 'exists:hc_cost_types,id'],
            'rows' => ['required', 'array'],
            'rows.*.work_unit_id' => ['required', 'exists:hc_work_units,id'],
            'rows.*.months' => ['required', 'array', 'size:12'],
            'rows.*.months.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $year = FiscalYear::findOrFail($data['fiscal_year_id']);
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        $type = CostType::findOrFail($data['cost_type_id']);
        if ($type->is_derived) {
            return back()->with('error', 'Nominal '.$type->name.' mengacu ke jenis biaya lain dan tidak dapat diinput manual.');
        }

        foreach ($data['rows'] as $row) {
            foreach ($row['months'] as $i => $amount) {
                $key = [
                    'fiscal_year_id' => $year->id,
                    'cost_type_id' => $type->id,
                    'work_unit_id' => $row['work_unit_id'],
                    'month' => $i + 1,
                    'scenario' => 'rkap',
                ];

                if ($amount === null || $amount === '') {
                    BudgetEntry::where($key)->delete();

                    continue;
                }

                BudgetEntry::updateOrCreate($key, ['amount' => $amount]);
            }
        }

        return back()->with('success', 'Nominal '.$type->name.' tersimpan.');
    }

    /** Unduh template Excel nominal untuk komponen (jenis biaya) terpilih. */
    public function template(Request $request)
    {
        $year = $this->resolveYear($request);
        $type = CostType::find($request->integer('komponen'));
        if (! $type || $type->parent_id === null) {
            return back()->with('error', 'Pilih komponen biaya yang valid terlebih dahulu.');
        }
        if ($type->is_derived) {
            return back()->with('error', 'Nominal '.$type->name.' otomatis/terkunci dan tidak dapat diimpor.');
        }

        $units = $this->budget->units()->where('is_active', true)->sortBy('sort_order')->values();
        $workbook = $this->spreadsheet->buildTemplate($year, $type, $this->unitRows($year->id, $type->id), $units);
        $filename = sprintf('Template_Nominal_%s_%d.xlsx', $type->code, $year->year);

        return response()->streamDownload(function () use ($workbook) {
            (new Xlsx($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Impor nominal RKAP satu komponen dari template Excel yang sudah diisi. */
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
        $type = CostType::find($parsed['costTypeId']);
        if (! $year || ! $type) {
            return back()->with('error', 'Informasi tahun/komponen pada file tidak dikenal. Gunakan template yang diunduh dari sistem.');
        }
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }
        if ($type->is_derived) {
            return back()->with('error', 'Nominal '.$type->name.' otomatis/terkunci dan tidak dapat diimpor.');
        }

        $validUnits = WorkUnit::pluck('id')->flip();
        $errors = $parsed['errors'];
        $saved = 0;
        $cleared = 0;

        DB::transaction(function () use ($parsed, $year, $type, $validUnits, &$errors, &$saved, &$cleared) {
            foreach ($parsed['items'] as $item) {
                if (! $validUnits->has($item['work_unit_id'])) {
                    $errors[] = "Baris {$item['row']}: unit kerja tidak dikenal, dilewati.";

                    continue;
                }
                foreach ($item['months'] as $i => $amount) {
                    $key = [
                        'fiscal_year_id' => $year->id,
                        'cost_type_id' => $type->id,
                        'work_unit_id' => $item['work_unit_id'],
                        'month' => $i + 1,
                        'scenario' => 'rkap',
                    ];
                    if ($amount === null) {
                        $cleared += BudgetEntry::where($key)->delete();

                        continue;
                    }
                    BudgetEntry::updateOrCreate($key, ['amount' => $amount]);
                    $saved++;
                }
            }
        });

        $redirect = redirect()->route('hc.nominal', [
            'tahun' => $year->year,
            'komponen' => $type->id,
        ]);

        if ($saved === 0 && $cleared === 0) {
            return $redirect->with('error', $errors
                ? "Tidak ada nilai tersimpan. {$errors[0]}"
                : 'Tidak ada nilai nominal pada file.');
        }

        $message = "Import nominal {$type->name} {$year->year}: {$saved} nilai tersimpan";
        $message .= $cleared ? ", {$cleared} dikosongkan." : '.';
        if ($errors) {
            $message .= ' '.count($errors).' sel dilewati — '.$errors[0];
        }

        return $redirect->with('success', $message);
    }

    /** Baris nominal per unit kerja (12 bulan) untuk satu jenis biaya. */
    private function unitRows(int $yearId, int $costTypeId): array
    {
        $entries = BudgetEntry::where('fiscal_year_id', $yearId)
            ->where('cost_type_id', $costTypeId)
            ->where('scenario', 'rkap')
            ->get();

        $units = $this->budget->units()->keyBy('id');

        $byUnit = [];
        foreach ($entries as $e) {
            $byUnit[$e->work_unit_id] ??= array_fill(0, 12, null);
            $byUnit[$e->work_unit_id][$e->month - 1] = (float) $e->amount;
        }

        return collect($byUnit)->map(fn ($months, $unitId) => [
            'work_unit_id' => (int) $unitId,
            'code' => $units->get($unitId)?->code,
            'name' => $units->get($unitId)?->name,
            'months' => $months,
        ])->sortBy('code')->values()->all();
    }
}
