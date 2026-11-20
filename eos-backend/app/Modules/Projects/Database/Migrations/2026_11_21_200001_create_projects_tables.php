<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small, general project registry (OP-9). Finance keeps its contract, budget and costs in its own tables keyed by
 * the project; a full projects module can extend these later. tsk_boards.project_id stays unlinked for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('status', 20)->default('planned'); // planned | active | on_hold | completed | closed
            $table->foreignId('branch_id')->nullable()->constrained('sys_branches')->restrictOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('usr_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('usr_users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'branch_id']);
        });

        Schema::create('prj_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('usr_users')->restrictOnDelete();
            $table->string('role', 20); // supervisor | member
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_project_members');
        Schema::dropIfExists('prj_projects');
    }
};
