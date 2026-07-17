<?php

namespace App\Http\Controllers\Turnover;

use App\Http\Controllers\Controller;
use App\Models\HcRkap\Employee;
use App\Models\Turnover\TurnoverEvent;
use App\Services\HcRkap\BudgetService;
use App\Services\HcRkap\EmployeeCostService;
use App\Services\Turnover\TurnoverService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Modul Turnover Pegawai (ekosistem Ethoz).
 *
 * Terintegrasi dengan roster modul RKAP HC: mencatat kejadian keluar dapat
 * sekaligus menonaktifkan pegawai (entri biaya bersumber pegawai ikut
 * disamakan), dan pegawai baru dari modul RKAP otomatis tercatat sebagai
 * kejadian masuk.
 */
class TurnoverController extends Controller
{
    public function __construct(
        private TurnoverService $turnover,
        private EmployeeCostService $costs,
        private BudgetService $budget,
    ) {}

    public function index(Request $request)
    {
        $year = (int) $request->input('tahun', now()->year);

        return Inertia::render('Turnover/Index', $this->turnover->pageData($year) + [
            'options' => [
                'employees' => Employee::where('is_active', true)->orderBy('name')
                    ->get(['id', 'name', 'work_unit_id', 'status', 'jabatan', 'join_date'])
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'name' => $e->name,
                        'work_unit_id' => $e->work_unit_id,
                        'status' => $e->status,
                        'jabatan' => $e->jabatan,
                        'join_date' => $e->join_date?->toDateString(),
                    ]),
                'units' => $this->budget->units()
                    ->map(fn ($u) => $u->only('id', 'code', 'name', 'parent_id'))->values(),
                'reasons' => collect(TurnoverService::REASONS)
                    ->map(fn ($r, $key) => ['key' => $key, 'label' => $r['label'], 'category' => $r['category']])
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $employee = ! empty($data['employee_id'])
            ? Employee::find($data['employee_id'])
            : null;

        // snapshot data pegawai roster; entri manual memakai isian formulir
        $event = TurnoverEvent::create([
            'employee_id' => $employee?->id,
            'employee_name' => $employee?->name ?? $data['employee_name'],
            'work_unit_id' => $employee?->work_unit_id ?? ($data['work_unit_id'] ?? null),
            'employee_status' => $employee?->status ?? ($data['employee_status'] ?? null),
            'jabatan' => $employee?->jabatan ?? ($data['jabatan'] ?? null),
            'type' => $data['type'],
            'event_date' => $data['event_date'],
            'reason' => $data['type'] === 'keluar' ? $data['reason'] : null,
            'category' => $data['type'] === 'keluar' ? $this->turnover->categoryOf($data['reason']) : null,
            'join_date' => $employee?->join_date?->toDateString() ?? ($data['join_date'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        // keluar + pegawai roster → nonaktifkan & samakan anggaran bersumber pegawai
        if ($event->type === 'keluar' && $employee && $request->boolean('deactivate', true) && $employee->is_active) {
            $employee->forceFill(['is_active' => false])->save();
            $this->costs->syncAllOpenYears();

            return back()->with('success', 'Kejadian keluar dicatat — pegawai dinonaktifkan dari roster & anggaran disamakan.');
        }

        return back()->with('success', 'Kejadian turnover dicatat.');
    }

    public function update(Request $request, TurnoverEvent $event)
    {
        $data = $this->validated($request, updating: true);

        $event->update([
            'employee_name' => $data['employee_name'],
            'work_unit_id' => $data['work_unit_id'] ?? null,
            'employee_status' => $data['employee_status'] ?? null,
            'jabatan' => $data['jabatan'] ?? null,
            'event_date' => $data['event_date'],
            'reason' => $event->type === 'keluar' ? $data['reason'] : null,
            'category' => $event->type === 'keluar' ? $this->turnover->categoryOf($data['reason']) : null,
            'join_date' => $data['join_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Kejadian turnover diperbarui.');
    }

    public function destroy(TurnoverEvent $event)
    {
        // menghapus kejadian keluar terakhir seorang pegawai = pembatalan:
        // pegawai diaktifkan kembali di roster dan anggaran disamakan
        $reactivate = $event->type === 'keluar'
            && $event->employee
            && ! $event->employee->is_active
            && ! TurnoverEvent::where('employee_id', $event->employee_id)
                ->where('type', 'keluar')->where('id', '!=', $event->id)
                ->where('event_date', '>', $event->event_date)->exists();

        $event->delete();

        if ($reactivate) {
            $event->employee->forceFill(['is_active' => true])->save();
            $this->costs->syncAllOpenYears();

            return back()->with('success', 'Kejadian dihapus — pegawai diaktifkan kembali di roster & anggaran disamakan.');
        }

        return back()->with('success', 'Kejadian turnover dihapus.');
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'type' => [Rule::excludeIf($updating), 'required', Rule::in(['masuk', 'keluar'])],
            'employee_id' => [Rule::excludeIf($updating), 'nullable', 'exists:hc_employees,id'],
            'employee_name' => ['required_without:employee_id', 'nullable', 'string', 'max:150'],
            'work_unit_id' => ['nullable', 'exists:hc_work_units,id'],
            'employee_status' => ['nullable', Rule::in(['tetap', 'kontrak', 'honor', 'direksi'])],
            'jabatan' => ['nullable', 'string', 'max:150'],
            'event_date' => ['required', 'date'],
            'reason' => ['nullable', 'required_if:type,keluar', Rule::in(array_keys(TurnoverService::REASONS))],
            'join_date' => ['nullable', 'date', 'before_or_equal:event_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'employee_name' => 'nama pegawai',
            'event_date' => 'tanggal kejadian',
            'reason' => 'alasan keluar',
            'join_date' => 'TMT masuk',
        ]);
    }
}
