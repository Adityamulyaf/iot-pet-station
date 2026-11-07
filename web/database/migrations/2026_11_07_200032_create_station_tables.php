<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id')->unique();
            $table->string('name');
            $table->string('location')->default('Rumah');
            $table->boolean('simulated')->default(true);
            $table->boolean('connected')->default(false);
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->dateTime('telemetry_at', 6)->nullable();
            $table->dateTime('state_at', 6)->nullable();
            $table->json('snapshot')->nullable();
            $table->json('actuators')->nullable();
            $table->string('scenario')->default('normal');
            $table->unsignedInteger('capacity_g')->default(1000);
            $table->timestamps();
        });
        Schema::create('device_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_id');
            $table->string('kind', 20);
            $table->json('payload');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('received_at', 6);
            $table->unique(['station_id', 'message_id']);
            $table->index(['station_id', 'kind', 'recorded_at']);
        });
        Schema::create('station_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->json('parameters');
            $table->string('status', 20)->default('pending');
            $table->string('reason')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('timed_out_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('result_at', 6)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['station_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_commands');
        Schema::dropIfExists('device_messages');
        Schema::dropIfExists('stations');
    }
};
