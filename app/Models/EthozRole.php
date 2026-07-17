<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Peran RBAC ekosistem Ethoz. Satu peran memberi akses ke satu atau lebih
 * modul (key modul di config/ethoz.php; '*' = seluruh modul).
 */
class EthozRole extends Model
{
    protected $table = 'ethoz_roles';

    protected $fillable = ['name', 'label', 'description'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'ethoz_role_user', 'role_id', 'user_id');
    }

    /** Key modul yang boleh diakses peran ini. */
    public function moduleKeys(): array
    {
        return DB::table('ethoz_module_role')
            ->where('role_id', $this->id)->pluck('module')->all();
    }

    /** Ganti daftar modul peran ini sekaligus. */
    public function syncModules(array $modules): void
    {
        DB::table('ethoz_module_role')->where('role_id', $this->id)->delete();
        DB::table('ethoz_module_role')->insert(
            collect($modules)->unique()->values()
                ->map(fn ($m) => ['role_id' => $this->id, 'module' => $m])->all()
        );
    }
}
