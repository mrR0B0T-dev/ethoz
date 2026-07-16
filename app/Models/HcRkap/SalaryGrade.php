<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class SalaryGrade extends Model
{
    protected $table = 'hc_salary_grades';

    protected $fillable = [
        'jabatan', 'code', 'level', 'sort_order',
        'salary_min', 'salary_mid', 'salary_max',
        'position_allowance', 'transport_allowance',
    ];

    protected $casts = [
        'level' => 'integer',
        'sort_order' => 'integer',
        'salary_min' => 'float',
        'salary_mid' => 'float',
        'salary_max' => 'float',
        'position_allowance' => 'float',
        'transport_allowance' => 'float',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class, 'salary_grade_id');
    }
}
