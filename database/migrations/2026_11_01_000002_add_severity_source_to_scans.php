<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('severity_source', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scans', fn (Blueprint $table) => $table->dropColumn('severity_source'));
    }
};
