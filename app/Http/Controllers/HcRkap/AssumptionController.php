<?php

namespace App\Http\Controllers\HcRkap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HcRkap\Concerns\ResolvesFiscalYear;
use App\Models\HcRkap\Assumption;
use App\Models\HcRkap\FiscalYear;
use App\Services\HcRkap\EmployeeCostService;
use App\Services\HcRkap\GradingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AssumptionController extends Controller
{
    use ResolvesFiscalYear;

    public function __construct(
        private EmployeeCostService $costs,
        private GradingService $grading,
    ) {}

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

        $year = FiscalYear::findOrFail($data['fiscal_year_id']);
        if ($year->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        $assumption = Assumption::create($data);

        return back()->with('success', 'Asumsi ditambahkan.'.$this->cascade($assumption));
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

        return back()->with('success', 'Asumsi diperbarui.'.$this->cascade($assumption));
    }

    public function destroy(Assumption $assumption)
    {
        if ($assumption->fiscalYear->status === 'final') {
            return back()->with('error', 'Tahun anggaran sudah final dan terkunci.');
        }

        $assumption->delete();

        // tanpa asumsi kenaikan, gaji kembali = gaji tahun sebelumnya (kenaikan 0%)
        return back()->with('success', 'Asumsi dihapus.'.$this->cascade($assumption));
    }

    /**
     * Terapkan perubahan asumsi ke biaya terkait. Asumsi kenaikan gaji mengubah
     * Gaji /bln pegawai yang punya Gaji Tahun Sebelumnya (base = prev + prev ×
     * kenaikan%), lalu grade disesuaikan dan entri biaya bersumber pegawai
     * (Gaji Dasar, Tunj. Jabatan, Transport) disamakan ulang. Komponen biaya
     * lain (THR, bonus, BPJS, kompensasi, fee, PPN) dihitung langsung dari
     * asumsi setiap kali halaman dibuka, jadi otomatis mengikuti nilai baru.
     */
    private function cascade(Assumption $assumption): string
    {
        $message = '';

        // asumsi kenaikan gaji mengubah Gaji Pokok /bln pegawai lebih dulu
        if (in_array($assumption->code, EmployeeCostService::KENAIKAN_CODES, true)) {
            $changed = $this->costs->recomputeSalariesFromAssumptions($assumption->fiscalYear);
            if ($changed > 0) {
                $this->grading->applyToAll();
                $message = " Gaji pokok {$changed} pegawai dihitung ulang dari gaji tahun sebelumnya.";
            }
        }

        // hampir semua asumsi (THR, bonus, BPJS, DPLK, PPh21, kenaikan, dll.)
        // memengaruhi komponen biaya bersumber pegawai → selalu samakan ulang
        // entri RKAP-nya agar Input Nominal tetap sinkron dengan Pegawai & Biaya
        FiscalYear::where('status', '!=', 'final')->get()
            ->each(fn ($y) => $this->costs->syncEmployeeSourcedEntries($y));

        return $message.' Komponen biaya bersumber pegawai disamakan ulang.';
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'exists:hc_fiscal_years,id'],
            'code' => ['required', 'string', 'max:60',
                Rule::unique('hc_assumptions')->where('fiscal_year_id', $request->integer('fiscal_year_id'))],
            'label' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(['kenaikan_gaji', 'tunjangan', 'pajak', 'fee', 'iuran', 'lainnya'])],
            'value_type' => ['required', Rule::in(['persen', 'nominal', 'bulan'])],
            'value' => ['required', 'numeric'],
            'applies_to' => ['nullable', 'array'],
            'applies_to.*' => [Rule::in(['tetap', 'kontrak', 'honor', 'direksi'])],
            'notes' => ['nullable', 'string'],
        ]);

        // multi status disimpan dipisah koma; kosong = semua status
        $data['applies_to'] = ! empty($data['applies_to'])
            ? implode(',', array_unique($data['applies_to']))
            : null;

        return $data;
    }
}
