<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->enum('severity', ['mild', 'moderate', 'severe', 'healthy', 'unassessed'])->change();
        });
        // Existing disease severity values came from unvalidated image heuristics.
        DB::table('scans')->where('severity', '!=', 'healthy')->update(['severity' => 'unassessed']);
    }

    public function down(): void
    {
        if (DB::table('scans')->where('severity', 'unassessed')->exists()) {
            throw new RuntimeException(
                'Cannot remove unassessed severity while scan records use it.'
            );
        }

        Schema::table('scans', function (Blueprint $table) {
            $table->enum('severity', ['mild', 'moderate', 'severe', 'healthy'])->change();
        });
    }
};
