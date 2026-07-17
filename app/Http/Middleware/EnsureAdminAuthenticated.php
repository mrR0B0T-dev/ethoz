<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias route "ethoz.auth" (alias lama: "admin.auth") — autentikasi wajib
 * ekosistem Ethoz: seluruh modul HCIS hanya bisa diakses setelah login;
 * pengguna yang belum login diarahkan ke halaman login.
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
