<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('junction_id');
            $table->string('outcome');
            $table->timestamp('received_at');

            $table->foreign('junction_id')->references('id')->on('junctions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};
