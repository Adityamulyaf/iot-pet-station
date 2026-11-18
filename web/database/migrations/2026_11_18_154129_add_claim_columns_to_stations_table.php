<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stations may exist without an owner until someone claims them with a one-time code.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('claim_code_hash')->nullable()->after('user_id');
            $table->dateTime('claimed_at')->nullable()->after('claim_code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['claim_code_hash', 'claimed_at']);
        });
        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
