<?php

namespace App\Models\HcRkap;

use Illuminate\Database\Eloquent\Model;

class WorkUnit extends Model
{
    protected $table = 'hc_work_units';

    protected $fillable = ['code', 'name', 'type', 'parent_id', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** ID unit ini beserta seluruh turunannya (untuk agregasi hierarkis). */
    public static function descendantIds(int $id): array
    {
        $byParent = self::query()
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [$id];
        $queue = [$id];
        while ($queue) {
            $current = array_shift($queue);
            foreach ($byParent->get($current, collect()) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
