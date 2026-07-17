<?php

namespace App\Models\Turnover;

use App\Models\HcRkap\Employee;
use App\Models\HcRkap\WorkUnit;
use Illuminate\Database\Eloquent\Model;

/**
 * Kejadian turnover (masuk/keluar pegawai). Kolom snapshot menjaga riwayat
 * tetap utuh walau pegawai/unit dihapus dari roster.
 */
class TurnoverEvent extends Model
{
    protected $table = 'hc_turnover_events';

    protected $fillable = [
        'employee_id', 'employee_name', 'work_unit_id', 'employee_status',
        'jabatan', 'type', 'event_date', 'reason', 'category', 'join_date', 'notes',
    ];

    protected $casts = [
        'event_date' => 'date',
        'join_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workUnit()
    {
        return $this->belongsTo(WorkUnit::class);
    }

    /** Masa kerja dalam bulan saat kejadian keluar; null bila TMT tak diketahui. */
    public function tenureMonths(): ?int
    {
        if ($this->type !== 'keluar' || ! $this->join_date) {
            return null;
        }

        return max(0, (int) $this->join_date->diffInMonths($this->event_date));
    }
}
