<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Cache key modul per request agar cek akses tidak mengulang query. */
    private ?array $ethozModuleKeys = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ── RBAC ekosistem Ethoz ─────────────────────────────────────────────

    public function ethozRoles()
    {
        return $this->belongsToMany(EthozRole::class, 'ethoz_role_user', 'user_id', 'role_id');
    }

    /** Gabungan key modul dari seluruh peran pengguna ('*' = semua modul). */
    public function moduleKeys(): array
    {
        return $this->ethozModuleKeys ??= \Illuminate\Support\Facades\DB::table('ethoz_module_role')
            ->join('ethoz_role_user', 'ethoz_role_user.role_id', '=', 'ethoz_module_role.role_id')
            ->where('ethoz_role_user.user_id', $this->id)
            ->distinct()->pluck('module')->all();
    }

    public function canAccessModule(string $key): bool
    {
        $keys = $this->moduleKeys();

        return in_array('*', $keys, true) || in_array($key, $keys, true);
    }

    /** Modul registri (config/ethoz.php) yang boleh diakses pengguna ini. */
    public function accessibleModules(): array
    {
        return collect(config('ethoz.modules'))
            ->filter(fn ($m, $key) => $this->canAccessModule($key))
            ->all();
    }
}
