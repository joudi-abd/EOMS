<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_adms_quarantined_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->foreignId('upload_id')->constrained('hr_adms_uploads')->restrictOnDelete();
            $table->string('external_id', 120);
            $table->string('device_user_id', 64);
            $table->dateTime('occurred_at');
            $table->string('direction', 12);
            $table->string('reason', 40)->default('future_event_time');
            $table->string('status', 16)->default('pending');
            $table->dateTime('received_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('punch_id')->nullable()->constrained('hr_attendance_punches')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['device_id', 'external_id'], 'hr_adms_quarantine_source');
            $table->index(['status', 'id'], 'hr_adms_quarantine_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_adms_quarantined_punches');
    }
};
