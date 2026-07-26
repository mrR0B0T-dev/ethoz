<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Perekam jejak audit ekosistem Ethoz. Menerjemahkan event Eloquent
 * (created / updated / deleted) dari model terdaftar menjadi entri
 * {@see ActivityLog}. Didaftarkan oleh {@see \App\Providers\ActivityLogServiceProvider}.
 */
class ActivityLogger
{
    /**
     * Pemetaan model → key modul (config/ethoz.php) untuk pengelompokan log.
     */
    private const MODULE_MAP = [
        'App\\Models\\User' => 'admin',
        'App\\Models\\EthozRole' => 'admin',
        'App\\Models\\HcRkap\\' => 'rkap',
        'App\\Models\\Turnover\\' => 'turnover',
    ];

    /** Atribut sensitif yang tidak boleh tersimpan di log. */
    private const HIDDEN = ['password', 'remember_token'];

    private const VERBS = [
        'created' => 'menambahkan',
        'updated' => 'mengubah',
        'deleted' => 'menghapus',
    ];

    /** Nama bulan untuk menyusun label entitas beranggaran per bulan. */
    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /** Nama tampil skenario anggaran. */
    private const SCENARIOS = [
        'rkap' => 'RKAP', 'realisasi' => 'Realisasi', 'prognosa' => 'Prognosa',
    ];

    /** Cache nama jenis biaya per-request agar tidak N+1 saat impor massal. */
    private array $costTypeNames = [];

    public function record(string $action, Model $model): void
    {
        // Aktivitas latar (seeder, migrasi, perintah artisan) bukan tindakan
        // pengguna aplikasi — jangan cemari jejak audit. Pengujian tetap
        // dicatat agar pipeline dapat diverifikasi.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $type = class_basename($model);
        $properties = $this->propertiesFor($action, $model);

        // "updated" tanpa perubahan berarti (mis. hanya timestamp) diabaikan
        if ($action === 'updated' && empty($properties['changes'])) {
            return;
        }

        // nama entitas yang terdampak — inti jejak audit: "apa yang diubah"
        $label = $this->labelFor($model);
        $typeLabel = ActivityLog::typeLabel($type);
        $verb = self::VERBS[$action] ?? $action;
        $user = Auth::user();

        ActivityLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'Sistem',
            'action' => $action,
            'module' => $this->moduleFor($model),
            'subject_type' => $type,
            'subject_id' => (string) $model->getKey(),
            'subject_label' => $label,
            'description' => trim("{$verb} {$typeLabel}".($label ? " \"{$label}\"" : '')),
            'properties' => $properties,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Nama terbaca entitas yang terdampak. Atribut nama tampil (label/title)
     * didahulukan atas slug teknis (name); entitas tanpa nama langsung
     * (mis. baris anggaran) disusun labelnya dari relasi. Selalu mengembalikan
     * pengenal — jatuh ke "#id" — agar target aktivitas tak pernah kosong.
     */
    private function labelFor(Model $model): string
    {
        if ($model instanceof BudgetEntry) {
            return $this->budgetEntryLabel($model);
        }

        foreach (['label', 'title', 'employee_name', 'name', 'jabatan', 'code', 'year'] as $attr) {
            if (filled($model->getAttribute($attr))) {
                return (string) $model->getAttribute($attr);
            }
        }

        return '#'.$model->getKey();
    }

    /** Label komposit baris anggaran: "Jenis Biaya · Bulan · Skenario". */
    private function budgetEntryLabel(BudgetEntry $entry): string
    {
        $parts = [$this->costTypeName($entry->cost_type_id)];

        if ($entry->month && isset(self::MONTHS[(int) $entry->month])) {
            $parts[] = self::MONTHS[(int) $entry->month];
        }
        if ($entry->scenario) {
            $parts[] = self::SCENARIOS[$entry->scenario] ?? Str::title($entry->scenario);
        }

        return implode(' · ', $parts);
    }

    /** Nama jenis biaya (di-cache) untuk menghindari N+1 pada banyak baris. */
    private function costTypeName(int|string|null $id): string
    {
        if ($id === null) {
            return 'Anggaran';
        }

        return $this->costTypeNames[$id] ??=
            (CostType::whereKey($id)->value('name') ?? "Jenis biaya #{$id}");
    }

    private function moduleFor(Model $model): ?string
    {
        $class = get_class($model);
        foreach (self::MODULE_MAP as $prefix => $module) {
            if ($class === $prefix || str_starts_with($class, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Ringkasan perubahan atribut, menyaring kolom sensitif & timestamp.
     */
    private function propertiesFor(string $action, Model $model): array
    {
        if ($action === 'deleted') {
            return [];
        }

        if ($action === 'created') {
            return ['attributes' => $this->clean($model->getAttributes())];
        }

        // updated → petakan atribut berubah menjadi { old, new }
        $changes = [];
        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, self::HIDDEN, true) || in_array($key, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $changes[$key] = ['old' => $model->getOriginal($key), 'new' => $new];
        }

        return ['changes' => $changes];
    }

    /** Buang atribut sensitif dari snapshot. */
    private function clean(array $attributes): array
    {
        foreach (self::HIDDEN as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }
}
