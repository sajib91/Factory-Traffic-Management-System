<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('junctions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->json('config');
            $table->string('mode')->default('AUTOMATIC');
            $table->string('phase')->nullable();
            $table->timestamp('step_started_at')->nullable();
            $table->timestamp('mode_started_at')->nullable();
            $table->json('desired_signals');
            $table->json('actual_signals');
            $table->json('emergency')->nullable();
            $table->json('manual')->nullable();
            $table->string('controller_status')->default('ONLINE');
            $table->json('last_sequences');
            $table->unsignedInteger('version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('junctions');
    }
};
