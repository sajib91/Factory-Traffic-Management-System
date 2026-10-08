<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queued_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('junction_id');
            $table->string('direction');
            $table->string('vehicle_id');
            $table->string('vehicle_type');
            $table->timestamp('arrived_at');

            $table->unique(['junction_id', 'vehicle_id']);
            $table->foreign('junction_id')->references('id')->on('junctions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queued_vehicles');
    }
};
