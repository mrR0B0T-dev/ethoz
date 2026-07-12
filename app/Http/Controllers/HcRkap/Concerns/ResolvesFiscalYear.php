<?php

namespace App\Http\Controllers\HcRkap\Concerns;

use App\Models\HcRkap\FiscalYear;
use Illuminate\Http\Request;

trait ResolvesFiscalYear
{
    /**
     * Tahun anggaran dari query ?tahun=YYYY; default tahun berstatus aktif,
     * atau tahun terbaru bila tidak ada yang aktif.
     */
    protected function resolveYear(Request $request): FiscalYear
    {
        $requested = $request->integer('tahun');

        if ($requested) {
            $year = FiscalYear::where('year', $requested)->first();
            if ($year) {
                return $year;
            }
        }

        return FiscalYear::where('status', 'aktif')->orderByDesc('year')->first()
            ?? FiscalYear::orderByDesc('year')->firstOrFail();
    }

    /** Daftar tahun untuk pemilih tahun di semua halaman. */
    protected function yearOptions(): array
    {
        return FiscalYear::orderByDesc('year')
            ->get(['id', 'year', 'label', 'status'])
            ->toArray();
    }
}
