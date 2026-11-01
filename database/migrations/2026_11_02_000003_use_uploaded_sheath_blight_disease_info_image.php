<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISEASE_CODE = 'sheath_blight';

    private const PREVIOUS_IMAGE_PATH = 'images/diseases/sheath_blight.jpg';

    private const DISEASE_INFO_IMAGE_PATH = 'images/diseases/sheath_blight-disease-info.jpg';

    public function up(): void
    {
        if (!Schema::hasTable('diseases')) {
            return;
        }

        DB::table('diseases')
            ->where('code', self::DISEASE_CODE)
            ->update([
                'image_path' => self::DISEASE_INFO_IMAGE_PATH,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('diseases')) {
            return;
        }

        DB::table('diseases')
            ->where('code', self::DISEASE_CODE)
            ->where('image_path', self::DISEASE_INFO_IMAGE_PATH)
            ->update([
                'image_path' => self::PREVIOUS_IMAGE_PATH,
                'updated_at' => now(),
            ]);
    }
};
