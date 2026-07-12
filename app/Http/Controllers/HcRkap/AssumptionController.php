<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\Assumption;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AssumptionController extends Controller
{
    use ResolvesFiscalYear;

    public function index(Request $request)
    {
        $year = $this->resolveYear($request);

        return Inertia::render('HcRkap/Asumsi', [
            'tahun' => $year,
            'years' => $this->yearOptions(),
            'assumptions' => $year->assumptions()
                ->orderBy('category')->orderBy('id')->get()
                ->groupBy('category'),
            'canEdit' => $year->status !== 'final',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $year = \App\Models\HcRkap\FiscalYear::findOrFail($data['fiscal_year_id']);
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        Assumption::create($data);

        return back()->with('success', 'Asumsi ditambahkan.');
    }

    public function update(Request $request, Assumption $assumption)
    {
        if ($assumption->fiscalYear->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        $assumption->update($request->validate([
            'label' => ['sometimes', 'string', 'max:200'],
            'value' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
        ]));

        return back()->with('success', 'Asumsi diperbarui.');
    }

    public function destroy(Assumption $assumption)
    {
        if ($assumption->fiscalYear->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        $assumption->delete();

        return back()->with('success', 'Asumsi dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'fiscal_year_id' => ['required', 'exists:hc_fiscal_years,id'],
            'code' => ['required', 'string', 'max:60',
                Rule::unique('hc_assumptions')->where('fiscal_year_id', $request->integer('fiscal_year_id'))],
            'label' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(['kenaikan_gaji', 'tunjangan', 'pajak', 'fee', 'iuran', 'lainnya'])],
            'value_type' => ['required', Rule::in(['persen', 'nominal', 'bulan'])],
            'value' => ['required', 'numeric'],
            'applies_to' => ['nullable', Rule::in(['tetap', 'kontrak', 'honor', 'direksi'])],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
