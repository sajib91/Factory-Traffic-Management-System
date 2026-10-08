<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('junction_id');
            $table->string('event_type');
            $table->string('direction')->nullable();
            $table->json('payload');
            $table->string('command_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['junction_id', 'created_at']);
            $table->foreign('junction_id')->references('id')->on('junctions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
