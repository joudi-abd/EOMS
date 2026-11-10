<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Each Sham Cash transfer file: its random DocId, the lines in it, and (once paid) the box it was paid from. */
    public function up(): void
    {
        Schema::create('pay_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained('pay_runs')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->string('doc_id', 20)->unique();
            $table->date('pay_date'); // exchange rate date and the payment date recorded on confirmation
            $table->json('line_ids');
            $table->json('rows'); // line_id, employee_id, name, wallet, amount — exactly what the file holds
            $table->unsignedInteger('count');
            $table->decimal('total', 18, 2);
            $table->string('note', 150);
            $table->foreignId('created_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable(); // a file that was never sent; its lines can go in a new file
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('box_id')->nullable()->constrained('fin_boxes')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_exports');
    }
};
