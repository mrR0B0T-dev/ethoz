<?php

namespace App\Providers;

use App\Support\ActivityLogger;
use Illuminate\Support\ServiceProvider;

/**
 * Mendaftarkan perekaman jejak audit (log aktivitas) ekosistem Ethoz.
 * Setiap operasi CRUD pada model terdaftar dicatat otomatis oleh
 * {@see ActivityLogger}. Menambah model baru ke audit cukup mendaftarkannya
 * pada array $loggable di bawah.
 */
class ActivityLogServiceProvider extends ServiceProvider
{
    /**
     * Model yang diaudit. ActivityLog sendiri sengaja tidak disertakan agar
     * pencatatan tidak memicu dirinya sendiri (rekursi).
     *
     * @var array<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private array $loggable = [
        \App\Models\User::class,
        \App\Models\EthozRole::class,
        \App\Models\HcRkap\Employee::class,
        \App\Models\HcRkap\BudgetEntry::class,
        \App\Models\HcRkap\Assumption::class,
        \App\Models\HcRkap\CostType::class,
        \App\Models\HcRkap\FiscalYear::class,
        \App\Models\HcRkap\SalaryGrade::class,
        \App\Models\HcRkap\WorkUnit::class,
        \App\Models\Turnover\TurnoverEvent::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class);
    }

    public function boot(): void
    {
        $logger = $this->app->make(ActivityLogger::class);

        foreach ($this->loggable as $model) {
            $model::created(fn ($m) => $logger->record('created', $m));
            $model::updated(fn ($m) => $logger->record('updated', $m));
            $model::deleted(fn ($m) => $logger->record('deleted', $m));
        }
    }
}
