<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->string('name', 120);
            $table->string('code', 20)->unique();
            // manager_id يُضاف لاحقاً في 4.2 بعد إنشاء جدول الموظفين
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_departments');
    }
};
