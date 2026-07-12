<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class Assumption extends Model
{
    protected $table = 'hc_assumptions';

    protected $fillable = [
        'fiscal_year_id', 'code', 'label', 'category',
        'value_type', 'value', 'applies_to', 'notes',
    ];

    protected $casts = ['value' => 'float'];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
