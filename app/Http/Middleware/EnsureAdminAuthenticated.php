<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias route "admin.auth" — melindungi seluruh halaman Sistem Informasi
 * RKAP HC; pengguna yang belum login diarahkan ke halaman login admin.
 */
class EnsureAdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
