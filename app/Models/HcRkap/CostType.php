<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class CostType extends Model
{
    protected $table = 'hc_cost_types';

    protected $fillable = ['code', 'name', 'parent_id', 'employee_status', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
