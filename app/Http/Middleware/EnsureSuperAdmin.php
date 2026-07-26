<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias route "ethoz.super" — pembatas khusus peran Super Admin. Dipakai untuk
 * fitur audit sensitif (log aktivitas) yang hanya boleh dilihat administrator.
 * Pengguna lain — meski punya akses modul Administrator — menerima 403.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return Inertia::render('Ethoz/Forbidden', [
                'module' => 'Log Aktivitas',
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
