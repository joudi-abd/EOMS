<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_block_minutes')->default(60);
            $table->decimal('overtime_multiplier', 5, 2)->default(2);
        });
        Schema::table('hr_attendance_devices', function (Blueprint $table) {
            $table->string('model', 64)->default('iFace 880 Plus');
        });

        // Only upgrade the untouched bootstrap on a genuinely unused installation.
        // Existing dated policies and attendance history must retain their rules.
        $bootstrap = DB::table('hr_attendance_policies')->first();
        if (DB::table('hr_attendance_policies')->count() === 1
            && ! DB::table('hr_attendance_days')->exists()
            && ! DB::table('hr_attendance_punches')->exists()
            && $bootstrap->effective_from === '1970-01-01'
            && $bootstrap->start_time === '09:00' && $bootstrap->end_time === '16:00'
            && (int) $bootstrap->grace_minutes === 30 && $bootstrap->grace_mode === 'threshold_full'
            && $bootstrap->timezone === 'Asia/Damascus'
            && json_decode($bootstrap->working_days, true) === [0, 1, 2, 3, 4, 6]) {
            DB::table('hr_attendance_policies')->where('id', $bootstrap->id)->update([
                'grace_minutes' => 45, 'grace_mode' => 'completed_blocks', 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('hr_attendance_devices', fn (Blueprint $t) => $t->dropColumn('model'));
        Schema::table('hr_attendance_policies', fn (Blueprint $t) => $t->dropColumn(['late_block_minutes', 'overtime_multiplier']));
    }
};
