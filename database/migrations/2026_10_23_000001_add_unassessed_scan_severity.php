<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE scans MODIFY severity ENUM('mild', 'moderate', 'severe', 'healthy', 'unassessed') NOT NULL");
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

        DB::statement("ALTER TABLE scans MODIFY severity ENUM('mild', 'moderate', 'severe', 'healthy') NOT NULL");
    }
};
