<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('junctions', function (Blueprint $table) {
            $table->json('pending')->nullable();
            $table->json('device_status')->nullable();
            $table->boolean('device_warning')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('junctions', function (Blueprint $table) {
            $table->dropColumn(['pending', 'device_status', 'device_warning']);
        });
    }
};
