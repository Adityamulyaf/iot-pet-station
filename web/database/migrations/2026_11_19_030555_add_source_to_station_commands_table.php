<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguish commands sent from the web from those created by a schedule.
     */
    public function up(): void
    {
        Schema::table('station_commands', function (Blueprint $table) {
            $table->string('source', 20)->default('web')->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('station_commands', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
