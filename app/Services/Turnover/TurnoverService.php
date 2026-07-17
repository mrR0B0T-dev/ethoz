<?php

namespace App\Services\Turnover;

use App\Models\HcRkap\Employee;
use App\Models\Turnover\TurnoverEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Metrik turnover pegawai per tahun kalender.
 *
 * Headcount historis direkonstruksi dari kejadian:
 *   headcount(t) = aktif saat ini − masuk sesudah t + keluar sesudah t.
 * Tingkat turnover memakai rumus standar: keluar ÷ rata-rata headcount
 * (awal + akhir tahun / 2) × 100%.
 */
class TurnoverService
{
    /** Alasan keluar → label & kategori (sukarela/tidak sukarela/lainnya). */
    public const REASONS = [
        'resign' => ['label' => 'Mengundurkan diri', 'category' => 'sukarela'],
        'phk' => ['label' => 'Pemutusan hubungan kerja (PHK)', 'category' => 'tidak_sukarela'],
        'kontrak_habis' => ['label' => 'Kontrak berakhir', 'category' => 'tidak_sukarela'],
        'pensiun' => ['label' => 'Pensiun', 'category' => 'lainnya'],
        'meninggal' => ['label' => 'Meninggal dunia', 'category' => 'lainnya'],
        'lainnya' => ['label' => 'Lainnya', 'category' => 'lainnya'],
    ];

    public function categoryOf(?string $reason): ?string
    {
        return $reason ? (self::REASONS[$reason]['category'] ?? 'lainnya') : null;
    }

    /** Catat kejadian masuk untuk pegawai baru di roster (dipanggil modul RKAP). */
    public function recordHire(Employee $employee): void
    {
        $exists = TurnoverEvent::where('employee_id', $employee->id)
            ->where('type', 'masuk')->exists();
        if ($exists) {
            return;
        }

        TurnoverEvent::create([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'work_unit_id' => $employee->work_unit_id,
            'employee_status' => $employee->status,
            'jabatan' => $employee->jabatan,
            'type' => 'masuk',
            'event_date' => $employee->join_date ?? now()->toDateString(),
            'join_date' => $employee->join_date ?? now()->toDateString(),
        ]);
    }

    public function pageData(int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();
        $today = now()->endOfDay();

        // seluruh kejadian dimuat sekali — rekonstruksi headcount in-memory
        $all = TurnoverEvent::select('type', 'event_date', 'work_unit_id')->get();
        $currentActive = Employee::where('is_active', true)->count();
        $headcountAt = function (Carbon $date) use ($all, $currentActive) {
            $after = $all->filter(fn ($e) => $e->event_date->gt($date));

            return max(0, $currentActive
                - $after->where('type', 'masuk')->count()
                + $after->where('type', 'keluar')->count());
        };

        $events = TurnoverEvent::with('workUnit:id,code,name')
            ->whereBetween('event_date', [$start, $end])
            ->orderByDesc('event_date')->orderByDesc('id')
            ->get();
        $masuk = $events->where('type', 'masuk');
        $keluar = $events->where('type', 'keluar');

        $hcAwal = $headcountAt($start->copy()->subDay());
        $hcAkhir = $headcountAt($end->min($today));
        $avgHc = ($hcAwal + $hcAkhir) / 2;
        $rate = fn (int $n) => $avgHc > 0 ? round($n / $avgHc * 100, 1) : null;

        // masa kerja pegawai keluar (bulan)
        $tenures = $keluar->map(fn ($e) => $e->tenureMonths())->filter(fn ($t) => $t !== null);
        $early = $tenures->filter(fn ($t) => $t < 12)->count();

        // tren bulanan: batang masuk/keluar + garis headcount akhir bulan
        $monthly = collect(range(1, 12))->map(function ($m) use ($year, $masuk, $keluar, $headcountAt, $today) {
            $monthEnd = Carbon::create($year, $m, 1)->endOfMonth();

            return [
                'bulan' => $m,
                'masuk' => $masuk->filter(fn ($e) => $e->event_date->month === $m)->count(),
                'keluar' => $keluar->filter(fn ($e) => $e->event_date->month === $m)->count(),
                'headcount' => $monthEnd->gt($today) ? null : $headcountAt($monthEnd),
            ];
        })->values()->all();

        return [
            'tahun' => $year,
            'years' => $this->yearOptions($year),
            'stats' => [
                'headcount_awal' => $hcAwal,
                'headcount_akhir' => $hcAkhir,
                'avg_headcount' => round($avgHc, 1),
                'masuk' => $masuk->count(),
                'keluar' => $keluar->count(),
                'net' => $masuk->count() - $keluar->count(),
                'turnover_rate' => $rate($keluar->count()),
                'hire_rate' => $rate($masuk->count()),
                'voluntary' => $keluar->where('category', 'sukarela')->count(),
                'voluntary_rate' => $rate($keluar->where('category', 'sukarela')->count()),
                'involuntary' => $keluar->where('category', 'tidak_sukarela')->count(),
                'other_exit' => $keluar->where('category', 'lainnya')->count(),
                'avg_tenure_months' => $tenures->isNotEmpty() ? round($tenures->avg(), 1) : null,
                'early_leavers' => $early, // keluar dengan masa kerja < 12 bulan
                'early_rate' => $keluar->count() ? round($early / $keluar->count() * 100, 1) : null,
            ],
            'monthly' => $monthly,
            'byReason' => $this->byReason($keluar),
            'byStatus' => $this->byStatus($masuk, $keluar),
            'byUnit' => $this->byUnit($masuk, $keluar, $all, $currentActive, $start, $end->min($today)),
            'events' => $events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'employee_id' => $e->employee_id,
                'employee_name' => $e->employee_name,
                'unit' => $e->workUnit?->code,
                'unit_name' => $e->workUnit?->name,
                'work_unit_id' => $e->work_unit_id,
                'employee_status' => $e->employee_status,
                'jabatan' => $e->jabatan,
                'event_date' => $e->event_date->toDateString(),
                'reason' => $e->reason,
                'reason_label' => $e->reason ? (self::REASONS[$e->reason]['label'] ?? $e->reason) : null,
                'category' => $e->category,
                'join_date' => $e->join_date?->toDateString(),
                'tenure_months' => $e->tenureMonths(),
                'notes' => $e->notes,
            ])->values()->all(),
        ];
    }

    private function byReason(Collection $keluar): array
    {
        $total = max($keluar->count(), 1);

        return $keluar->groupBy('reason')->map(fn ($group, $reason) => [
            'reason' => $reason,
            'label' => self::REASONS[$reason]['label'] ?? ($reason ?: 'Tidak diisi'),
            'category' => $this->categoryOf($reason),
            'count' => $group->count(),
            'pct' => round($group->count() / $total * 100, 1),
        ])->sortByDesc('count')->values()->all();
    }

    private function byStatus(Collection $masuk, Collection $keluar): array
    {
        return collect(['tetap', 'kontrak', 'honor', 'direksi'])->map(fn ($s) => [
            'status' => $s,
            'masuk' => $masuk->where('employee_status', $s)->count(),
            'keluar' => $keluar->where('employee_status', $s)->count(),
        ])->filter(fn ($r) => $r['masuk'] || $r['keluar'])->values()->all();
    }

    /** Rekap per unit kerja: masuk, keluar, headcount aktif, dan rate per unit. */
    private function byUnit(Collection $masuk, Collection $keluar, Collection $all, int $currentActive, Carbon $start, Carbon $end): array
    {
        $activeByUnit = Employee::where('is_active', true)
            ->whereNotNull('work_unit_id')
            ->selectRaw('work_unit_id, COUNT(*) n')->groupBy('work_unit_id')
            ->pluck('n', 'work_unit_id');

        $unitHcAt = function (Carbon $date, int $unitId) use ($all, $activeByUnit) {
            $after = $all->filter(fn ($e) => $e->work_unit_id === $unitId && $e->event_date->gt($date));

            return max(0, (int) ($activeByUnit[$unitId] ?? 0)
                - $after->where('type', 'masuk')->count()
                + $after->where('type', 'keluar')->count());
        };

        $unitIds = $masuk->pluck('work_unit_id')
            ->merge($keluar->pluck('work_unit_id'))->filter()->unique();

        $units = \App\Models\HcRkap\WorkUnit::whereIn('id', $unitIds)->get(['id', 'code', 'name']);

        return $unitIds->map(function ($unitId) use ($masuk, $keluar, $units, $unitHcAt, $start, $end, $activeByUnit) {
            $unit = $units->firstWhere('id', $unitId);
            $out = $keluar->where('work_unit_id', $unitId)->count();
            $avg = ($unitHcAt($start->copy()->subDay(), $unitId) + $unitHcAt($end, $unitId)) / 2;

            return [
                'unit' => $unit?->code ?? '—',
                'unit_name' => $unit?->name ?? 'Unit terhapus',
                'headcount' => (int) ($activeByUnit[$unitId] ?? 0),
                'masuk' => $masuk->where('work_unit_id', $unitId)->count(),
                'keluar' => $out,
                'rate' => $avg > 0 ? round($out / $avg * 100, 1) : null,
            ];
        })->sortByDesc('keluar')->values()->all();
    }

    /** Pilihan tahun: seluruh tahun yang punya kejadian + tahun berjalan & terpilih. */
    private function yearOptions(int $selected): array
    {
        return TurnoverEvent::selectRaw('DISTINCT YEAR(event_date) y')->pluck('y')
            ->push(now()->year)->push($selected)
            ->unique()->sortDesc()->values()->all();
    }
}
