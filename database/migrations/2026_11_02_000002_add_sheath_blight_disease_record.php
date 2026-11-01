<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISEASE_CODE = 'sheath_blight';

    private const DISEASE_NAME = 'Sheath Blight';

    private const SCIENTIFIC_NAME = 'Rhizoctonia solani (Thanatephorus cucumeris)';

    private const IMAGE_PATH = 'images/diseases/sheath_blight.jpg';

    private const DESCRIPTION = 'A fungal disease caused by Rhizoctonia solani that commonly starts on rice leaf sheaths near the waterline and may spread to leaf blades.';

    public function up(): void
    {
        if (!Schema::hasTable('diseases') || DB::table('diseases')->where('code', self::DISEASE_CODE)->exists()) {
            return;
        }

        $now = now();

        DB::table('diseases')->insert([
            'name' => self::DISEASE_NAME,
            'code' => self::DISEASE_CODE,
            'scientific_name' => self::SCIENTIFIC_NAME,
            'image_path' => self::IMAGE_PATH,
            'description' => self::DESCRIPTION,
            'symptoms' => 'Small greenish-gray oval lesions on leaf sheaths, often near the waterline, that can enlarge into irregular lesions with grayish-white centers and dark brown borders.',
            'causes' => 'Caused by Rhizoctonia solani. The fungus forms small sclerotia on or near lesions; dense canopies and humid conditions can favor disease development.',
            'prevention' => 'Scout lower leaf sheaths, avoid excessively dense planting and excess nitrogen, and follow balanced crop nutrition and field-sanitation advice from local agricultural staff.',
            'recommended_treatment' => 'Use integrated field management and monitor whether lesions are spreading. If fungicide treatment is being considered, ask the local agriculture office for a product registered for rice sheath blight and follow its label exactly.',
            'chemical_treatments' => json_encode([
                ['title' => 'Locally registered fungicide for sheath blight', 'rate' => 'Follow the product label', 'timing' => 'Use only a product labeled for rice sheath blight in your area; confirm product, timing, and rate with local agricultural staff.'],
            ], JSON_THROW_ON_ERROR),
            'organic_treatments' => json_encode([
                ['title' => 'Canopy and nitrogen management', 'rate' => 'Follow local crop recommendations', 'timing' => 'Avoid excessive nitrogen and overly dense crop canopies; scout the lower leaf sheaths regularly.'],
                ['title' => 'Crop-residue management', 'rate' => 'After harvest', 'timing' => 'Manage infected crop residues according to local extension advice.'],
            ], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('diseases')) {
            return;
        }

        DB::table('diseases')
            ->where('code', self::DISEASE_CODE)
            ->where('name', self::DISEASE_NAME)
            ->where('scientific_name', self::SCIENTIFIC_NAME)
            ->where('image_path', self::IMAGE_PATH)
            ->where('description', self::DESCRIPTION)
            ->delete();
    }
};
