<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'hc_employees';

    protected $fillable = [
        'name', 'jabatan', 'work_unit_id', 'status', 'salary_grade_id', 'grade_source',
        'base_salary', 'prev_year_salary', 'position_allowance', 'transport_allowance',
        'join_date', 'birth_date', 'ptkp_status', 'cuti_month', 'cuti_entitlement',
        'notes', 'is_active',
    ];

    protected $casts = [
        'base_salary' => 'float',
        'prev_year_salary' => 'float',
        'position_allowance' => 'float',
        'transport_allowance' => 'float',
        'join_date' => 'date',
        'birth_date' => 'date',
        'cuti_month' => 'integer',
        'is_active' => 'boolean',
    ];

    public function workUnit()
    {
        return $this->belongsTo(WorkUnit::class, 'work_unit_id');
    }

    public function salaryGrade()
    {
        return $this->belongsTo(SalaryGrade::class, 'salary_grade_id');
    }
}
