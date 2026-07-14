<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class CostType extends Model
{
    protected $table = 'hc_cost_types';

    protected $fillable = [
        'code', 'name', 'parent_id', 'employee_status',
        'is_derived', 'derived_note', 'employee_source', 'sort_order', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean', 'is_derived' => 'boolean'];

    /** Daftar status pegawai terkait (kolom disimpan dipisah koma). */
    public function statusList(): array
    {
        return $this->employee_status
            ? array_values(array_filter(explode(',', $this->employee_status)))
            : [];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
