<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('ethoz.name'),
                'tagline' => config('ethoz.tagline'),
            ],
            'auth' => [
                'user' => $request->user()?->only('name', 'email'),
                // key modul yang boleh diakses — navigasi lintas modul di
                // frontend hanya menampilkan modul yang diizinkan (RBAC)
                'modules' => $request->user()?->moduleKeys() ?? [],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
