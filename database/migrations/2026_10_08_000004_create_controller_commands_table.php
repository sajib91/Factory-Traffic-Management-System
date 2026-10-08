<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controller_commands', function (Blueprint $table) {
            $table->string('command_id')->primary();
            $table->string('junction_id');
            $table->json('desired_signals');
            $table->string('status')->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();

            $table->foreign('junction_id')->references('id')->on('junctions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controller_commands');
    }
};
