<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use App\Models\HcRkap\WorkUnit;
use App\Services\HcRkap\YearGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MasterController extends Controller
{
    use ResolvesFiscalYear;

    public function index(Request $request)
    {
        $stats = BudgetEntry::selectRaw(
            "fiscal_year_id, SUM(CASE WHEN scenario = 'rkap' THEN amount ELSE 0 END) as rkap_total,
             SUM(CASE WHEN scenario = 'realisasi' THEN amount ELSE 0 END) as realisasi_total"
        )->groupBy('fiscal_year_id')->get()->keyBy('fiscal_year_id');

        return Inertia::render('HcRkap/Master', [
            'tahun' => $this->resolveYear($request),
            'years' => FiscalYear::orderByDesc('year')->get()->map(fn ($y) => [
                ...$y->only('id', 'year', 'label', 'status', 'notes'),
                'rkap_total' => (float) ($stats[$y->id]->rkap_total ?? 0),
                'realisasi_total' => (float) ($stats[$y->id]->realisasi_total ?? 0),
            ]),
            'units' => WorkUnit::orderBy('sort_order')->get(),
            'costTypes' => CostType::orderBy('sort_order')->get(),
        ]);
    }

    // ── Tahun anggaran ───────────────────────────────────────────────────────

    public function storeYear(Request $request, YearGeneratorService $generator)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100', 'unique:hc_fiscal_years,year'],
            'source_year_id' => ['nullable', 'exists:hc_fiscal_years,id'],
            'basis' => ['nullable', Rule::in(['rkap', 'realisasi', 'prognosa'])],
            'kenaikan' => ['nullable', 'array'],
            'kenaikan.tetap' => ['nullable', 'numeric', 'between:-50,100'],
            'kenaikan.kontrak' => ['nullable', 'numeric', 'between:-50,100'],
            'kenaikan.honor' => ['nullable', 'numeric', 'between:-50,100'],
            'kenaikan.direksi' => ['nullable', 'numeric', 'between:-50,100'],
        ]);

        if (! empty($data['source_year_id'])) {
            $source = FiscalYear::findOrFail($data['source_year_id']);
            $generator->generate(
                $source,
                $data['year'],
                $data['basis'] ?? 'rkap',
                array_map('floatval', array_filter(
                    $data['kenaikan'] ?? [],
                    fn ($v) => $v !== null && $v !== ''
                )),
            );

            return back()->with('success', "RKAP {$data['year']} berhasil digenerate dari {$source->year}.");
        }

        FiscalYear::create([
            'year' => $data['year'],
            'label' => 'RKAP '.$data['year'],
            'status' => 'draft',
        ]);

        return back()->with('success', "Tahun anggaran {$data['year']} dibuat (kosong).");
    }

    public function updateYear(Request $request, FiscalYear $fiscalYear)
    {
        $fiscalYear->update($request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['draft', 'aktif', 'final'])],
            'notes' => ['nullable', 'string'],
        ]));

        // hanya satu tahun berstatus aktif
        if ($fiscalYear->status === 'aktif') {
            FiscalYear::where('id', '!=', $fiscalYear->id)
                ->where('status', 'aktif')
                ->update(['status' => 'final']);
        }

        return back()->with('success', 'Tahun anggaran diperbarui.');
    }

    public function destroyYear(FiscalYear $fiscalYear)
    {
        if ($fiscalYear->status !== 'draft') {
            return back()->with('error', 'Hanya tahun berstatus draft yang dapat dihapus.');
        }

        $fiscalYear->delete();

        return back()->with('success', 'Tahun anggaran dihapus.');
    }

    // ── Unit kerja ───────────────────────────────────────────────────────────

    public function storeUnit(Request $request)
    {
        WorkUnit::create($this->unitData($request));

        return back()->with('success', 'Unit kerja ditambahkan.');
    }

    public function updateUnit(Request $request, WorkUnit $workUnit)
    {
        $workUnit->update($this->unitData($request, $workUnit->id));

        return back()->with('success', 'Unit kerja diperbarui.');
    }

    public function destroyUnit(WorkUnit $workUnit)
    {
        if ($workUnit->children()->exists()) {
            return back()->with('error', 'Hapus/pindahkan dulu sub-unit di bawah unit ini.');
        }
        if (BudgetEntry::where('work_unit_id', $workUnit->id)->exists()) {
            return back()->with('error', 'Unit memiliki data anggaran; nonaktifkan saja.');
        }

        $workUnit->delete();

        return back()->with('success', 'Unit kerja dihapus.');
    }

    private function unitData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30',
                Rule::unique('hc_work_units', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['group', 'department', 'section'])],
            'parent_id' => ['nullable', 'exists:hc_work_units,id', Rule::notIn([$ignoreId])],
            'is_active' => ['boolean'],
        ]);
    }

    // ── Jenis biaya ──────────────────────────────────────────────────────────

    public function storeCostType(Request $request)
    {
        $data = $this->costTypeData($request);
        $data['sort_order'] = (CostType::max('sort_order') ?? 0) + 1;
        CostType::create($data);

        return back()->with('success', 'Jenis biaya ditambahkan.');
    }

    public function updateCostType(Request $request, CostType $costType)
    {
        $costType->update($this->costTypeData($request, $costType->id));

        return back()->with('success', 'Jenis biaya diperbarui.');
    }

    public function destroyCostType(CostType $costType)
    {
        if ($costType->children()->exists()) {
            return back()->with('error', 'Hapus/pindahkan dulu komponen di bawah jenis biaya ini.');
        }
        if (BudgetEntry::where('cost_type_id', $costType->id)->exists()) {
            return back()->with('error', 'Jenis biaya memiliki data anggaran; nonaktifkan saja.');
        }

        $costType->delete();

        return back()->with('success', 'Jenis biaya dihapus.');
    }

    private function costTypeData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40',
                Rule::unique('hc_cost_types', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'exists:hc_cost_types,id', Rule::notIn([$ignoreId])],
            'employee_status' => ['nullable', 'array'],
            'employee_status.*' => [Rule::in(['tetap', 'kontrak', 'honor', 'direksi'])],
            'is_derived' => ['boolean'],
            'derived_note' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
        ]);

        // multi status disimpan dipisah koma; kosong = lintas status
        $data['employee_status'] = ! empty($data['employee_status'])
            ? implode(',', array_unique($data['employee_status']))
            : null;

        return $data;
    }
}
