<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique(); // sha256 hex، لا نخزّن التوكن نفسه
            $table->json('scopes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_api_clients');
    }
};