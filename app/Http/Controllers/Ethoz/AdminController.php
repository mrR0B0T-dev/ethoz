<?php

namespace App\Http\Controllers\Ethoz;

use App\Http\Controllers\Controller;
use App\Models\EthozRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Modul Administrator Ethoz: kelola pengguna, peran, dan hak akses modul.
 * Peran "super-admin" terkunci pada akses seluruh modul ('*') dan tidak
 * dapat dihapus agar ekosistem tidak pernah kehilangan administratornya.
 */
class AdminController extends Controller
{
    public function index()
    {
        return Inertia::render('Ethoz/Admin', [
            'users' => User::with('ethozRoles:id,label')->orderBy('name')->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role_ids' => $u->ethozRoles->pluck('id'),
                    'role_labels' => $u->ethozRoles->pluck('label'),
                ]),
            'roles' => EthozRole::withCount('users')->orderBy('label')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'label' => $r->label,
                    'description' => $r->description,
                    'modules' => $r->moduleKeys(),
                    'users_count' => $r->users_count,
                ]),
            'modules' => collect(config('ethoz.modules'))
                ->map(fn ($m, $key) => ['key' => $key, 'name' => $m['name']])->values(),
        ]);
    }

    // ── Pengguna ─────────────────────────────────────────────────────────

    public function storeUser(Request $request)
    {
        $data = $this->validatedUser($request);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->ethozRoles()->sync($data['roles'] ?? []);

        return back()->with('success', 'Pengguna ditambahkan.');
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $this->validatedUser($request, $user);

        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();
        $user->ethozRoles()->sync($data['roles'] ?? []);

        return back()->with('success', 'Pengguna diperbarui.');
    }

    public function destroyUser(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Tidak dapat menghapus akun sendiri.');
        }

        $user->delete();

        return back()->with('success', 'Pengguna dihapus.');
    }

    private function validatedUser(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:ethoz_roles,id'],
        ], [], ['password' => 'kata sandi', 'roles' => 'peran']);
    }

    // ── Peran & akses modul ──────────────────────────────────────────────

    public function storeRole(Request $request)
    {
        $data = $this->validatedRole($request);

        $role = EthozRole::create([
            'name' => $this->uniqueSlug($data['label']),
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
        ]);
        $role->syncModules($data['modules'] ?? []);

        return back()->with('success', 'Peran ditambahkan.');
    }

    public function updateRole(Request $request, EthozRole $role)
    {
        $data = $this->validatedRole($request);

        $role->update([
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
        ]);

        // super-admin selalu memegang akses seluruh modul
        $role->syncModules($role->name === 'super-admin' ? ['*'] : ($data['modules'] ?? []));

        return back()->with('success', 'Peran diperbarui.');
    }

    public function destroyRole(EthozRole $role)
    {
        if ($role->name === 'super-admin') {
            return back()->with('error', 'Peran Super Admin tidak dapat dihapus.');
        }

        $role->delete(); // keanggotaan & akses modul ikut terhapus (FK cascade)

        return back()->with('success', 'Peran dihapus.');
    }

    private function validatedRole(Request $request): array
    {
        $validKeys = array_keys(config('ethoz.modules'));
        $validKeys[] = '*';

        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'modules' => ['nullable', 'array'],
            'modules.*' => [Rule::in($validKeys)],
        ], [], ['label' => 'nama peran', 'modules' => 'akses modul']);
    }

    private function uniqueSlug(string $label): string
    {
        $base = Str::slug($label) ?: 'peran';
        $slug = $base;
        for ($i = 2; EthozRole::where('name', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
