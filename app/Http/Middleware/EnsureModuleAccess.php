<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias route "ethoz.module:<key>" — RBAC per modul ekosistem Ethoz.
 * Pengguna tanpa peran yang memberi akses ke modul terkait menerima halaman
 * 403 Forbidden; navigasi modul juga disembunyikan dari portal.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canAccessModule($module)) {
            return Inertia::render('Ethoz/Forbidden', [
                'module' => config("ethoz.modules.{$module}.name", $module),
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
