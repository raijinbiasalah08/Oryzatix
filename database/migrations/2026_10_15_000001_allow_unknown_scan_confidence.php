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
            $table->decimal('confidence', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('scans')->whereNull('confidence')->exists()) {
            throw new \RuntimeException(
                'Cannot restore a required scan confidence while scans with unknown confidence exist.'
            );
        }

        Schema::table('scans', function (Blueprint $table) {
            $table->decimal('confidence', 5, 2)->nullable(false)->change();
        });
    }
};
