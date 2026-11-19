<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily feed/water times per station, in Asia/Jakarta local time.
     */
    public function up(): void
    {
        Schema::create('station_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->string('time', 5);
            $table->json('days');
            $table->boolean('enabled')->default(true);
            $table->date('last_run_on')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->string('last_note')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_schedules');
    }
};
