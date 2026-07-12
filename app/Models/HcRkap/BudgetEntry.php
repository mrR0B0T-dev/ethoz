<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class BudgetEntry extends Model
{
    protected $table = 'hc_budget_entries';

    protected $fillable = [
        'fiscal_year_id', 'cost_type_id', 'work_unit_id',
        'month', 'scenario', 'amount',
    ];

    protected $casts = ['amount' => 'float', 'month' => 'integer'];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function costType()
    {
        return $this->belongsTo(CostType::class, 'cost_type_id');
    }

    public function workUnit()
    {
        return $this->belongsTo(WorkUnit::class, 'work_unit_id');
    }
}
