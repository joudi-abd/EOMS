<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trello-style task boards. A board holds ordered lists, a list holds ordered cards. Two list kinds carry meaning:
 * "done" (the assignee says it is finished) and "finished" (the board owner confirmed it). Nothing is deleted:
 * boards, lists and cards are archived and can be restored. tsk_activities is both the card history and the
 * board's change feed (its id is the cursor clients poll or receive over the broadcast channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsk_boards', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // department | team | personal | project
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->unsignedBigInteger('project_id')->nullable(); // linked when the projects module exists
            $table->foreignId('created_by')->constrained('usr_users');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['type', 'archived_at']);
        });

        Schema::create('tsk_board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('tsk_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('usr_users');
            $table->string('role', 10); // owner | member | viewer
            $table->foreignId('added_by')->nullable()->constrained('usr_users');
            $table->timestamps();
            $table->unique(['board_id', 'user_id']);
        });

        Schema::create('tsk_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('tsk_boards')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 10)->default('normal'); // normal | done | finished
            $table->decimal('position', 24, 10);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['board_id', 'position']);
        });

        Schema::create('tsk_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('tsk_boards')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 20);
            $table->timestamps();
        });

        Schema::create('tsk_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('tsk_boards')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('tsk_lists');
            $table->json('template'); // title, description, priority, assignee_ids, label_ids, checklist
            $table->string('frequency', 10); // daily | weekly | monthly
            $table->json('weekdays')->nullable(); // weekly: Carbon dayOfWeek values
            $table->unsignedTinyInteger('month_day')->nullable(); // monthly: 1..31 (clamped to the month's last day)
            $table->unsignedSmallInteger('due_in_days')->nullable(); // working days after creation; null = no due date
            $table->date('next_run_on');
            $table->date('last_run_on')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('usr_users');
            $table->timestamps();
            $table->index(['active', 'next_run_on']);
        });

        Schema::create('tsk_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('tsk_boards')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('tsk_lists');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('position', 24, 10);
            // Where the card stood before "done", so a rejected card goes back there.
            $table->foreignId('previous_list_id')->nullable()->constrained('tsk_lists')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('usr_users');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('usr_users');
            $table->foreignId('recurrence_id')->nullable()->constrained('tsk_recurrences')->nullOnDelete();
            // Reminder bookkeeping: the due date each reminder was sent for, so a changed date reminds again.
            $table->date('reminded_soon_for')->nullable();
            $table->date('reminded_overdue_for')->nullable();
            $table->foreignId('created_by')->constrained('usr_users');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['list_id', 'position']);
            $table->index(['board_id', 'archived_at']);
            $table->index('due_date');
        });

        Schema::create('tsk_card_assignees', function (Blueprint $table) {
            $table->foreignId('card_id')->constrained('tsk_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('usr_users');
            $table->timestamps();
            $table->primary(['card_id', 'user_id']);
        });

        Schema::create('tsk_card_watchers', function (Blueprint $table) {
            $table->foreignId('card_id')->constrained('tsk_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('usr_users');
            $table->primary(['card_id', 'user_id']);
        });

        Schema::create('tsk_card_labels', function (Blueprint $table) {
            $table->foreignId('card_id')->constrained('tsk_cards')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('tsk_labels')->cascadeOnDelete();
            $table->primary(['card_id', 'label_id']);
        });

        Schema::create('tsk_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('tsk_cards')->cascadeOnDelete();
            $table->string('title', 150);
            $table->decimal('position', 24, 10);
            $table->timestamps();
        });

        Schema::create('tsk_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('tsk_checklists')->cascadeOnDelete();
            $table->string('text', 500);
            $table->boolean('done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('usr_users');
            $table->timestamp('done_at')->nullable();
            $table->decimal('position', 24, 10);
            $table->timestamps();
        });

        Schema::create('tsk_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('tsk_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('usr_users');
            $table->text('body');
            $table->json('mentions')->nullable(); // user ids
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tsk_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('tsk_cards')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->constrained('usr_users');
            $table->timestamps();
        });

        Schema::create('tsk_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('tsk_boards')->cascadeOnDelete();
            $table->unsignedBigInteger('card_id')->nullable(); // kept after archiving; no FK so history survives
            $table->foreignId('user_id')->nullable()->constrained('usr_users');
            $table->string('action', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['board_id', 'id']);
            $table->index(['card_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['tsk_activities', 'tsk_attachments', 'tsk_comments', 'tsk_checklist_items', 'tsk_checklists', 'tsk_card_labels',
            'tsk_card_watchers', 'tsk_card_assignees', 'tsk_cards', 'tsk_recurrences', 'tsk_labels', 'tsk_lists', 'tsk_board_members', 'tsk_boards'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
