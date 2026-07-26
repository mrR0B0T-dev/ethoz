<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Entri log aktivitas (jejak audit) ekosistem Ethoz. Bersifat append-only:
 * dibuat otomatis oleh {@see \App\Support\ActivityLogger}, hanya dibaca lewat
 * modul Administrator (Super Admin). Tidak ada kolom updated_at — entri tak
 * pernah diubah setelah tercatat.
 */
class ActivityLog extends Model
{
    protected $table = 'ethoz_activity_logs';

    /** Entri tidak pernah diperbarui — kelola created_at saja. */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'user_name', 'action', 'module', 'subject_type',
        'subject_id', 'subject_label', 'description', 'properties', 'ip_address',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    /**
     * Nama tampil (Bahasa Indonesia) tiap jenis entitas yang diaudit —
     * sumber tunggal dipakai perekam ({@see \App\Support\ActivityLogger})
     * maupun tampilan log agar konsisten.
     */
    public const TYPE_LABELS = [
        'User' => 'Pengguna',
        'EthozRole' => 'Peran',
        'Employee' => 'Pegawai',
        'BudgetEntry' => 'Anggaran',
        'Assumption' => 'Asumsi',
        'CostType' => 'Jenis Biaya',
        'FiscalYear' => 'Tahun Anggaran',
        'SalaryGrade' => 'Grade Upah',
        'WorkUnit' => 'Unit Kerja',
        'TurnoverEvent' => 'Kejadian Turnover',
    ];

    /** Nama tampil terbaca untuk jenis entitas (cadangan: headline kelas). */
    public static function typeLabel(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        return self::TYPE_LABELS[$type] ?? Str::headline($type);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
