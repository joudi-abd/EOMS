<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_policies', function (Blueprint $t) {
            $t->id();
            $t->date('effective_from')->unique();
            $t->string('timezone', 64);
            $t->json('working_days'); // Carbon dayOfWeek: Sunday=0, Friday=5.
            $t->string('start_time', 5);
            $t->string('end_time', 5);
            $t->unsignedSmallInteger('grace_minutes')->default(30);
            $t->string('grace_mode', 24)->default('threshold_full');
            $t->string('pairing_mode', 24)->default('first_last');
            $t->unsignedSmallInteger('duplicate_seconds')->default(60);
            $t->unsignedSmallInteger('finalize_after_minutes')->default(120);
            $t->timestamps();
        });
        DB::table('hr_attendance_policies')->insert([
            'effective_from' => '1970-01-01',
            'timezone' => 'Asia/Damascus',
            'working_days' => json_encode([0, 1, 2, 3, 4, 6]),
            'start_time' => '09:00', 'end_time' => '16:00',
            'grace_minutes' => 30, 'grace_mode' => 'threshold_full',
            'pairing_mode' => 'first_last', 'duplicate_seconds' => 60,
            'finalize_after_minutes' => 120, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::create('hr_attendance_devices', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('serial_number', 100)->unique();
            $t->string('timezone', 64)->default('Asia/Damascus');
            $t->string('token_hash', 64)->unique();
            $t->boolean('is_active')->default(true);
            $t->dateTime('last_seen_at')->nullable();
            $t->dateTime('complete_through')->nullable(); // Explicit bridge assertion, UTC.
            $t->timestamps();
        });
        Schema::create('hr_attendance_bindings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $t->string('device_user_id', 64);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->timestamps();
            $t->index(['device_id', 'device_user_id'], 'hr_att_binding_lookup');
        });
        Schema::create('hr_attendance_punches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            $t->string('external_id', 120);
            $t->string('device_user_id', 64);
            $t->dateTime('occurred_at'); // UTC; API requires explicit offset.
            $t->string('direction', 12)->default('unknown');
            $t->dateTime('received_at');
            $t->unique(['device_id', 'external_id'], 'hr_att_punch_source');
            $t->index(['employee_id', 'occurred_at'], 'hr_att_punch_employee_time');
        });
        Schema::create('hr_attendance_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $t->date('work_date');
            $t->foreignId('policy_id')->constrained('hr_attendance_policies')->restrictOnDelete();
            $t->string('status', 32)->default('pending');
            $t->dateTime('first_in')->nullable();
            $t->dateTime('last_out')->nullable();
            $t->unsignedInteger('worked_minutes')->nullable();
            $t->unsignedInteger('late_actual_minutes')->nullable();
            $t->unsignedInteger('late_minutes')->nullable();
            $t->unsignedInteger('early_minutes')->nullable();
            $t->unsignedInteger('extra_minutes')->nullable(); // Unapproved, informational only.
            $t->json('flags');
            $t->boolean('coverage_complete')->default(false);
            $t->string('source_signature', 64)->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->boolean('pending_changes')->default(false);
            $t->foreignId('approved_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date'], 'hr_att_day_employee_date');
        });
        Schema::create('hr_attendance_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('day_id')->constrained('hr_attendance_days')->restrictOnDelete();
            $t->string('type', 16); // times or absence
            $t->dateTime('first_in')->nullable();
            $t->dateTime('last_out')->nullable();
            $t->string('reason', 1000);
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('base_version');
            $t->foreignId('requested_by')->constrained('usr_users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->string('review_reason', 1000)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['corrections', 'days', 'punches', 'bindings', 'devices', 'policies'] as $table) {
            Schema::dropIfExists('hr_attendance_'.$table);
        }
    }
};
