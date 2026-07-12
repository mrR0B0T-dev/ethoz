<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hc_fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('label')->nullable();
            // draft: masih disusun, aktif: tahun berjalan (monitoring), final: terkunci
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hc_work_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('type', 20); // group | department | section
            $table->foreignId('parent_id')->nullable()->constrained('hc_work_units')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hc_cost_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('hc_cost_types')->nullOnDelete();
            // tetap | kontrak | honor | direksi | null = semua status
            $table->string('employee_status', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hc_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('hc_fiscal_years')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label');
            $table->string('category', 30); // kenaikan_gaji | tunjangan | pajak | fee | iuran | lainnya
            $table->string('value_type', 20); // persen | nominal | bulan
            $table->decimal('value', 18, 4)->default(0);
            $table->string('applies_to', 20)->nullable(); // status pegawai terkait
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'code']);
        });

        Schema::create('hc_employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('work_unit_id')->nullable()->constrained('hc_work_units')->nullOnDelete();
            $table->string('status', 20)->index(); // tetap | kontrak | honor | direksi
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('position_allowance', 18, 2)->default(0);
            $table->decimal('transport_allowance', 18, 2)->default(0);
            $table->date('join_date')->nullable(); // TMT / awal PKWT
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hc_budget_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('hc_fiscal_years')->cascadeOnDelete();
            $table->foreignId('cost_type_id')->constrained('hc_cost_types')->cascadeOnDelete();
            $table->foreignId('work_unit_id')->constrained('hc_work_units')->cascadeOnDelete();
            $table->unsignedTinyInteger('month'); // 1-12
            $table->string('scenario', 20); // rkap | realisasi | prognosa
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(
                ['fiscal_year_id', 'cost_type_id', 'work_unit_id', 'month', 'scenario'],
                'hc_budget_entries_unique'
            );
            $table->index(['fiscal_year_id', 'scenario']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hc_budget_entries');
        Schema::dropIfExists('hc_employees');
        Schema::dropIfExists('hc_assumptions');
        Schema::dropIfExists('hc_cost_types');
        Schema::dropIfExists('hc_work_units');
        Schema::dropIfExists('hc_fiscal_years');
    }
};
