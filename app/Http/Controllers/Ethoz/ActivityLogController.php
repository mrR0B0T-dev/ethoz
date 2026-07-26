<?php

namespace App\Http\Controllers\Ethoz;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Log aktivitas modul Administrator — jejak audit seluruh operasi CRUD
 * pengguna. Hanya-lihat: dilindungi middleware "ethoz.super" (Super Admin) dan
 * tidak menyediakan aksi ubah/hapus apa pun.
 */
class ActivityLogController extends Controller
{
    /** Jumlah entri per halaman. */
    private const PER_PAGE = 30;

    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'action' => (string) $request->query('action', ''),
            'module' => (string) $request->query('module', ''),
            'user' => $request->query('user', ''),
        ];

        $logs = ActivityLog::query()
            ->when($filters['action'], fn ($q, $v) => $q->where('action', $v))
            ->when($filters['module'], fn ($q, $v) => $q->where('module', $v))
            ->when($filters['user'] !== '' && $filters['user'] !== null,
                fn ($q) => $q->where('user_id', $filters['user']))
            ->when($filters['search'], fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('subject_label', 'like', "%{$v}%")
                    ->orWhere('description', 'like', "%{$v}%")
                    ->orWhere('user_name', 'like', "%{$v}%");
            }))
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'user_name' => $log->user_name,
                'action' => $log->action,
                'module' => $log->module,
                'module_name' => $log->module ? config("ethoz.modules.{$log->module}.name", $log->module) : null,
                'subject_type' => $log->subject_type,
                'subject_type_label' => ActivityLog::typeLabel($log->subject_type),
                'subject_label' => $log->subject_label,
                'description' => $log->description,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Ethoz/ActivityLog', [
            'logs' => $logs,
            'filters' => $filters,
            'filterOptions' => [
                'actions' => [
                    ['value' => 'created', 'label' => 'Tambah'],
                    ['value' => 'updated', 'label' => 'Ubah'],
                    ['value' => 'deleted', 'label' => 'Hapus'],
                ],
                'modules' => collect(config('ethoz.modules'))
                    ->map(fn ($m, $key) => ['value' => $key, 'label' => $m['name']])->values(),
                'users' => User::orderBy('name')->get(['id', 'name'])
                    ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            ],
        ]);
    }
}
