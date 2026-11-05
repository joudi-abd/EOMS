<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_devices', function (Blueprint $table) {
            $table->string('connection_mode', 16)->default('bridge');
            $table->string('adms_source_ip', 45)->nullable();
        });
        Schema::create('hr_adms_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->string('body_hash', 64);
            $table->longText('raw_body');
            $table->unsignedInteger('record_count');
            $table->dateTime('received_at');
            $table->unique(['device_id', 'body_hash'], 'hr_adms_upload_unique');
        });
        Schema::create('hr_adms_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->foreignId('binding_id')->constrained('hr_attendance_bindings')->restrictOnDelete();
            $table->string('device_user_id', 64);
            $table->string('status', 40)->default('queued_user');
            $table->unsignedTinyInteger('finger_id');
            $table->foreignId('requested_by')->constrained('usr_users')->restrictOnDelete();
            $table->dateTime('expires_at');
            $table->foreignId('confirmed_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('confirmation_note', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('hr_adms_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('hr_adms_enrollments')->restrictOnDelete();
            $table->string('kind', 16);
            $table->text('payload');
            $table->string('status', 20)->default('queued');
            $table->string('return_code', 24)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'kind'], 'hr_adms_command_step');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_adms_commands');
        Schema::dropIfExists('hr_adms_enrollments');
        Schema::dropIfExists('hr_adms_uploads');
        Schema::table('hr_attendance_devices', function (Blueprint $table) {
            $table->dropColumn(['connection_mode', 'adms_source_ip']);
        });
    }
};
