<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();

            // الرقم الوظيفي بصيغة U-XXXXXXXX يُولَّد في الخدمة
            $table->string('employee_no', 11)->unique();

            // حساب الدخول اختياري في الجدول، لكنه إلزامي في قاعدة العمل
            $table->foreignId('user_id')->nullable()->unique()->constrained('usr_users')->nullOnDelete();

            $table->string('first_name', 80);
            $table->string('last_name', 80);

            // الرقم الوطني إلزامي: مشفّر بـ cast، والبصمة للبحث ومنع التكرار
            $table->text('national_id');
            $table->string('national_id_hash', 64)->unique();

            $table->date('birth_date')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();

            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->restrictOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained('hr_job_titles')->restrictOnDelete();

            // المدير المباشر اختياري، وإن حُذف الموظف الأعلى لا يُحذف من تحته
            $table->foreignId('manager_id')->nullable()->constrained('hr_employees')->nullOnDelete();

            // رقم المستخدم داخل جهاز UA300 Pro
            $table->unsignedInteger('biometric_code')->nullable()->unique();

            $table->string('sham_cash_wallet', 30)->nullable();

            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->date('hire_date');
            $table->date('termination_date')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employees');
    }
};