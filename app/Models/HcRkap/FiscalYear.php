<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $table = 'hc_fiscal_years';

    protected $fillable = ['year', 'label', 'status', 'notes'];

    public function assumptions()
    {
        return $this->hasMany(Assumption::class, 'fiscal_year_id');
    }

    public function budgetEntries()
    {
        return $this->hasMany(BudgetEntry::class, 'fiscal_year_id');
    }
}
