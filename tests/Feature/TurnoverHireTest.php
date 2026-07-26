<?php

namespace Tests\Feature;

use App\Models\EthozRole;
use App\Models\HcRkap\Employee;
use App\Models\HcRkap\SalaryGrade;
use App\Models\Turnover\TurnoverEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Form "Pegawai Masuk" modul Turnover: mendaftarkan pegawai baru langsung ke
 * roster RKAP HC dengan aturan skala upah & grading yang sama, dan mencatat
 * kejadian masuk otomatis.
 */
class TurnoverHireTest extends TestCase
{
    use RefreshDatabase;

    private function actingTurnoverUser(): User
    {
        $role = EthozRole::firstOrCreate(['name' => 'turnover-user'], ['label' => 'Turnover']);
        $role->syncModules(['turnover']);

        $user = User::create([
            'name' => 'TO User',
            'email' => 'to@test.local',
            'password' => Hash::make('password'),
        ]);
        $user->ethozRoles()->attach($role->id);
        $this->actingAs($user);

        return $user;
    }

    public function test_pegawai_masuk_creates_roster_employee_and_hire_event(): void
    {
        $this->actingTurnoverUser();

        $this->post(route('turnover.store'), [
            'type' => 'masuk',
            'name' => 'Budi Santoso',
            'status' => 'kontrak',
            'base_salary' => 6_000_000,
            'join_date' => '2026-07-01',
            'birth_date' => '1995-05-05',
            'ptkp_status' => 'TK/0',
        ])->assertRedirect();

        $employee = Employee::where('name', 'Budi Santoso')->first();
        $this->assertNotNull($employee, 'Pegawai harus tersimpan ke roster.');
        $this->assertTrue($employee->is_active);
        $this->assertSame('kontrak', $employee->status);
        $this->assertSame('2026-07-01', $employee->join_date->toDateString());

        // kejadian masuk otomatis tercatat & tanggalnya mengikuti tgl join
        $event = TurnoverEvent::where('employee_id', $employee->id)->where('type', 'masuk')->first();
        $this->assertNotNull($event, 'Kejadian masuk harus tercatat otomatis.');
        $this->assertSame('2026-07-01', $event->event_date->toDateString());
    }

    public function test_non_tetap_allowances_are_forced_to_zero(): void
    {
        $this->actingTurnoverUser();

        $this->post(route('turnover.store'), [
            'type' => 'masuk',
            'name' => 'Honor A',
            'status' => 'honor',
            'base_salary' => 5_000_000,
            'position_allowance' => 999_000,
            'transport_allowance' => 999_000,
        ])->assertRedirect();

        $e = Employee::where('name', 'Honor A')->first();
        $this->assertEquals(0.0, (float) $e->position_allowance);
        $this->assertEquals(0.0, (float) $e->transport_allowance);
    }

    public function test_base_salary_must_fall_within_selected_grade_range(): void
    {
        $this->actingTurnoverUser();

        $grade = SalaryGrade::create([
            'jabatan' => 'Uji Staff', 'code' => 'UJI-1', 'level' => (SalaryGrade::max('level') ?? 0) + 1, 'sort_order' => 900,
            'salary_min' => 4_000_000, 'salary_mid' => 4_500_000, 'salary_max' => 5_000_000,
            'position_allowance' => 500_000, 'transport_allowance' => 300_000,
        ]);

        $this->post(route('turnover.store'), [
            'type' => 'masuk',
            'name' => 'Out Of Band',
            'status' => 'tetap',
            'salary_grade_id' => $grade->id,
            'base_salary' => 9_000_000, // di luar rentang 4jt–5jt
        ])->assertSessionHasErrors('base_salary');

        $this->assertDatabaseMissing('hc_employees', ['name' => 'Out Of Band']);
    }

    public function test_selected_grade_sets_allowances_for_tetap(): void
    {
        $this->actingTurnoverUser();

        $grade = SalaryGrade::create([
            'jabatan' => 'Uji Manajer', 'code' => 'UJI-9', 'level' => (SalaryGrade::max('level') ?? 0) + 1, 'sort_order' => 901,
            'salary_min' => 8_000_000, 'salary_mid' => 10_000_000, 'salary_max' => 12_000_000,
            'position_allowance' => 1_500_000, 'transport_allowance' => 800_000,
        ]);

        $this->post(route('turnover.store'), [
            'type' => 'masuk',
            'name' => 'Manajer Tetap',
            'status' => 'tetap',
            'salary_grade_id' => $grade->id,
            'base_salary' => 10_000_000,
        ])->assertRedirect();

        $e = Employee::where('name', 'Manajer Tetap')->first();
        $this->assertEquals(1_500_000.0, (float) $e->position_allowance);
        $this->assertEquals(800_000.0, (float) $e->transport_allowance);
        $this->assertSame($grade->id, $e->salary_grade_id);
    }
}
