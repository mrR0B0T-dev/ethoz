<?php

namespace Tests\Feature;

use App\Models\EthozRole;
use App\Models\HcRkap\BudgetEntry;
use App\Models\HcRkap\CostType;
use App\Models\HcRkap\FiscalYear;
use App\Models\HcRkap\WorkUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Jejak audit modul Administrator: pencatatan CRUD otomatis + akses hanya-lihat
 * yang dibatasi peran Super Admin.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleName, array $modules): User
    {
        $role = EthozRole::firstOrCreate(
            ['name' => $roleName],
            ['label' => ucfirst($roleName)],
        );
        $role->syncModules($modules);

        $user = User::create([
            'name' => "User {$roleName}",
            'email' => "{$roleName}@test.local",
            'password' => Hash::make('password'),
        ]);
        $user->ethozRoles()->attach($role->id);

        return $user;
    }

    public function test_model_crud_is_recorded_to_activity_log(): void
    {
        $admin = $this->makeUser('super-admin', ['*']);
        $this->actingAs($admin);

        $role = EthozRole::create(['name' => 'analis', 'label' => 'Analis']);

        $this->assertDatabaseHas('ethoz_activity_logs', [
            'action' => 'created',
            'subject_type' => 'EthozRole',
            'subject_label' => 'Analis',
            'user_id' => $admin->id,
            'user_name' => $admin->name,
            'module' => 'admin',
        ]);

        $role->update(['label' => 'Analis Senior']);

        $this->assertDatabaseHas('ethoz_activity_logs', [
            'action' => 'updated',
            'subject_type' => 'EthozRole',
            'user_id' => $admin->id,
        ]);
    }

    public function test_target_entity_name_is_recorded_for_named_models(): void
    {
        $admin = $this->makeUser('super-admin', ['*']);
        $this->actingAs($admin);

        $employee = new WorkUnit(['code' => 'HCS', 'name' => 'Human Capital', 'type' => 'department']);
        $employee->save();

        // nama entitas terdampak ikut tercatat sebagai subject_label
        $this->assertDatabaseHas('ethoz_activity_logs', [
            'action' => 'created',
            'subject_type' => 'WorkUnit',
            'subject_label' => 'Human Capital',
        ]);
    }

    public function test_budget_entry_label_is_composed_from_its_relations(): void
    {
        $admin = $this->makeUser('super-admin', ['*']);
        $this->actingAs($admin);

        $year = FiscalYear::create(['year' => 2027, 'status' => 'draft']);
        $unit = WorkUnit::create(['code' => 'HCS', 'name' => 'Human Capital', 'type' => 'department']);
        $cost = CostType::create(['code' => 'GAJI', 'name' => 'Biaya Gaji']);

        // baris anggaran tak punya kolom nama — label disusun dari relasinya
        BudgetEntry::create([
            'fiscal_year_id' => $year->id,
            'cost_type_id' => $cost->id,
            'work_unit_id' => $unit->id,
            'month' => 3,
            'scenario' => 'realisasi',
            'amount' => 1_000_000,
        ]);

        $this->assertDatabaseHas('ethoz_activity_logs', [
            'action' => 'created',
            'subject_type' => 'BudgetEntry',
            'subject_label' => 'Biaya Gaji · Maret · Realisasi',
            'module' => 'rkap',
        ]);
    }

    public function test_super_admin_can_view_activity_log(): void
    {
        $admin = $this->makeUser('super-admin', ['*']);

        $this->actingAs($admin)
            ->get(route('ethoz.admin.activity'))
            ->assertOk();
    }

    public function test_non_super_admin_with_module_access_is_forbidden(): void
    {
        // punya akses modul Administrator, tetapi bukan peran Super Admin
        $manager = $this->makeUser('admin-biasa', ['admin']);

        $this->actingAs($manager)
            ->get(route('ethoz.admin.activity'))
            ->assertForbidden();
    }

    public function test_activity_log_is_view_only(): void
    {
        $admin = $this->makeUser('super-admin', ['*']);

        // tidak ada rute tulis apa pun ke log — hanya GET yang terdaftar
        $this->actingAs($admin)
            ->post('/ethoz/admin/log-aktivitas')
            ->assertStatus(405);
    }
}
