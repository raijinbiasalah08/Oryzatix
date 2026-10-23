<?php

namespace App\Http\Controllers;

use App\Models\RiceScan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Exception;

class RiceScanController extends Controller
{
    /** Panel dataset: 5 diseases + healthy leaf (~1k images each class). */
    private array $supportedDatasetKeys = [
        'blast',
        'blb',
        'brown_spot',
        'sheath_blight',
        'tungro',
        'healthy',
    ];

    private function isDatasetSupported(string $key): bool
    {
        return in_array($key, $this->supportedDatasetKeys, true);
    }

    private function unsupportedScanResponse(
        ?string $imageUrl,
        string $reason = 'not_in_dataset',
        array $details = [],
        int $httpStatus = 200
    ): JsonResponse
    {
        $messages = [
            'uncertain_prediction' => [
                'en' => 'A rice leaf was detected, but the disease result is uncertain. Try a clear, close-up photo of one leaf.',
                'tl' => 'May nakitang dahon ng palay ngunit hindi tiyak ang resulta ng sakit. Subukan ang malinaw at malapit na larawan ng isang dahon.',
            ],
            'blurry_image' => [
                'en' => 'This photo is too blurry to scan reliably. Retake it with the rice leaf in focus and good light.',
                'tl' => 'Masyadong malabo ang larawang ito para masuri nang maayos. Kumuha ulit ng malinaw na larawan ng dahon ng palay sa sapat na liwanag.',
            ],
            'model_unavailable' => [
                'en' => 'The rice-leaf scanner is temporarily unavailable. Please try again in a moment.',
                'tl' => 'Pansamantalang hindi magamit ang rice-leaf scanner. Subukan muli maya-maya.',
            ],
            'not_rice_leaf' => [
                'en' => 'This image does not appear to show a rice leaf. Upload a clear, close-up photo of a rice leaf to get a diagnosis.',
                'tl' => 'Mukhang walang dahon ng palay sa larawang ito. Mag-upload ng malinaw at malapit na larawan ng dahon ng palay para masuri ito.',
            ],
        ];
        $message = $messages[$reason] ?? [
            'en' => 'This image does not match a supported rice-leaf class. Use a clear photo of a rice leaf.',
            'tl' => 'Hindi tumutugma ang larawang ito sa mga suportadong klase ng dahon ng palay. Gumamit ng malinaw na larawan ng dahon ng palay.',
        ];

        $validation = $details['validation'] ?? null;
        $quality = $details['quality'] ?? null;
        $diagnosis = $details['diagnosis'] ?? null;
        $riceLeafValidated = $details['rice_leaf_validated'] ?? false;

        return response()->json([
            'success' => $httpStatus < 500,
            'recognized' => false,
            'rice_leaf_validated' => $riceLeafValidated === true,
            'validation' => is_array($validation) ? $validation : null,
            'quality' => is_array($quality) ? $quality : null,
            'saved' => false,
            'reason' => $reason,
            'message' => $message['en'],
            'message_en' => $message['en'],
            'message_tl' => $message['tl'],
            'server_error' => $httpStatus >= 500,
            'supported_diseases' => [
                'Bacterial Leaf Blight (BLB) — Mild (≤ 25%), Moderate (26% – 60%), Severe (> 60%)',
                'Rice Leaf Blast (Magnaporthe oryzae)',
                'Brown Spot (Bipolaris oryzae)',
                'Sheath Blight (Rhizoctonia solani)',
                'Rice Tungro Disease (RTBV/RTSV)',
                'Healthy Rice Leaves',
            ],
            'scan' => [
                'recognized' => false,
                'image_url' => $imageUrl,
                'date' => now()->format('M j, Y'),
                'time' => now()->format('g:i A'),
                'confidence' => is_array($diagnosis) && isset($diagnosis['confidence'])
                    ? round((float) $diagnosis['confidence'] * 100, 1)
                    : null,
                'minimum_confidence' => is_array($diagnosis) && isset($diagnosis['minimum_confidence'])
                    ? round((float) $diagnosis['minimum_confidence'] * 100, 1)
                    : null,
                'disease_key' => is_array($diagnosis) ? ($diagnosis['disease_key'] ?? null) : null,
            ],
        ], $httpStatus);
    }

    private function hasServerScanAssets(): bool
    {
        $paths = [
            base_path('inference/predict_scan.py'),
            base_path('oryzatix_app/assets/models/rice_leaf_validator.tflite'),
            base_path('oryzatix_app/assets/models/mobilenetv2_rice_leaf.tflite'),
            base_path('oryzatix_app/assets/models/class_labels.json'),
            base_path('oryzatix_app/assets/models/scan_validation_thresholds.json'),
        ];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                return false;
            }
        }

        return true;
    }

    /** Run the separate binary gate and the disease model; reject malformed outputs. */
    private function predictScanWithServerModels(string $imagePath): ?array
    {
        $scriptPath = base_path('inference/predict_scan.py');
        $validatorPath = base_path('oryzatix_app/assets/models/rice_leaf_validator.tflite');
        $diseasePath = base_path('oryzatix_app/assets/models/mobilenetv2_rice_leaf.tflite');
        $labelsPath = base_path('oryzatix_app/assets/models/class_labels.json');
        $thresholdsPath = base_path('oryzatix_app/assets/models/scan_validation_thresholds.json');
        $python = env('ORYZATIX_PYTHON', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');

        $pipes = [];
        try {
            $process = @proc_open(
                [$python, $scriptPath, $validatorPath, $diseasePath, $labelsPath, $thresholdsPath, $imagePath],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                null,
                ['bypass_shell' => true]
            );
        } catch (\Throwable $e) {
            Log::warning('Unable to start rice-scan model inference.', ['error' => $e->getMessage()]);
            return null;
        }

        if (!is_resource($process)) {
            Log::warning('Unable to start rice-scan model inference.');
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            Log::warning('Rice-scan model inference failed.', [
                'exit_code' => $exitCode,
                'error' => trim((string) $stderr),
            ]);
            return null;
        }

        $result = json_decode(trim((string) $stdout), true);
        $statuses = ['diagnosed', 'not_rice_leaf', 'blurry_image', 'uncertain_prediction'];
        if (!is_array($result) || !in_array($result['status'] ?? null, $statuses, true)
            || !is_array($result['validation'] ?? null) || !is_array($result['quality'] ?? null)) {
            Log::warning('Rice-scan model returned a malformed response.');
            return null;
        }

        $status = $result['status'];
        $validation = $result['validation'];
        $quality = $result['quality'];
        $riceProbability = $validation['rice_leaf_probability'] ?? null;
        $riceThreshold = $validation['rice_leaf_threshold'] ?? null;
        $focusScore = $quality['laplacian_variance'] ?? null;
        $focusThreshold = $quality['minimum_laplacian_variance'] ?? null;
        foreach ([$riceThreshold, $focusScore, $focusThreshold] as $number) {
            if (!is_numeric($number) || !is_finite((float) $number) || (float) $number < 0.0) {
                Log::warning('Rice-scan model returned invalid validation metrics.');
                return null;
            }
        }
        if ((float) $riceThreshold > 1.0 || ($riceProbability !== null
            && (!is_numeric($riceProbability) || !is_finite((float) $riceProbability)
                || (float) $riceProbability < 0.0 || (float) $riceProbability > 1.0))) {
            Log::warning('Rice-scan model returned invalid probabilities.');
            return null;
        }

        if ($status === 'blurry_image') {
            return ($quality['status'] ?? null) === 'blurry' ? $result : null;
        }
        if ($status === 'not_rice_leaf') {
            return ($validation['rice_leaf'] ?? null) === false && $riceProbability !== null
                && (float) $riceProbability < (float) $riceThreshold ? $result : null;
        }

        if (($result['rice_leaf_validated'] ?? null) !== true
            || ($validation['rice_leaf'] ?? null) !== true
            || $riceProbability === null || (float) $riceProbability < (float) $riceThreshold
            || ($quality['status'] ?? null) !== 'passed'
            || (float) $focusScore < (float) $focusThreshold) {
            Log::warning('Rice-scan model did not confirm a valid, clear rice-leaf image.');
            return null;
        }

        $diagnosis = $result['diagnosis'] ?? null;
        if (!is_array($diagnosis) || !is_string($diagnosis['disease_key'] ?? null)
            || !is_numeric($diagnosis['confidence'] ?? null)
            || !is_finite((float) $diagnosis['confidence'])
            || (float) $diagnosis['confidence'] < 0.0 || (float) $diagnosis['confidence'] > 1.0) {
            Log::warning('Rice-scan model returned an invalid diagnosis score.');
            return null;
        }

        if ($status === 'diagnosed') {
            $diseaseKey = $diagnosis['disease_key'];
            $threshold = $diagnosis['minimum_confidence'] ?? null;
            $supportedModelKeys = ['blast', 'blb', 'brown_spot', 'healthy', 'sheath_blight', 'tungro'];
            if (!in_array($diseaseKey, $supportedModelKeys, true) || !is_numeric($threshold)
                || !is_finite((float) $threshold) || (float) $threshold < 0.0 || (float) $threshold > 1.0
                || (float) $diagnosis['confidence'] < (float) $threshold) {
                Log::warning('Rice-scan model returned an unsupported or below-threshold diagnosis.');
                return null;
            }
        }

        return $result;
    }

    // ── DISEASE COLOR SIGNATURES (for visual matching) ───────────────────
    private $diseaseSignatures = [
        'blast' => [
            'brown_ratio' => [0.06, 0.30],
            'white_ratio' => [0.04, 0.20],
            'brown_dark_ratio' => [0.03, 0.15],
            'yellow_ratio'   => [0.00, 0.04],
            'name_visual'    => 'Leaf Blast (spindle-shaped lesions with brown borders + gray centers)',
        ],
        'blb' => [
            'yellow_ratio'   => [0.08, 0.40],
            'white_ratio'    => [0.05, 0.25],
            'green_pale'     => true,
            'brown_ratio'    => [0.00, 0.04],
            'name_visual'    => 'Bacterial Leaf Blight (yellow/orange wavy edges + white streaks)',
        ],
        'brown_spot' => [
            'brown_ratio'    => [0.08, 0.35],
            'brown_dark_ratio' => [0.04, 0.20],
            'spotty'         => true,
            'yellow_ratio'   => [0.00, 0.06],
            'name_visual'    => 'Brown Spot (numerous small round dark brown spots)',
        ],
        'tungro' => [
            'yellow_ratio'   => [0.15, 0.60],
            'orange_ratio'   => [0.05, 0.25],
            'green_mottled'  => true,
            'brown_ratio'    => [0.00, 0.05],
            'name_visual'    => 'Rice Tungro (overall yellow-orange leaf discoloration)',
        ],
        'sheath_blight' => [
            'green_ratio'    => [0.20, 0.65],
            'gray_ratio'     => [0.05, 0.30],
            'brown_ratio'    => [0.05, 0.25],
            'name_visual'    => 'Sheath Blight (irregular oval snake-skin lesions on lower sheaths)',
        ],
        'healthy' => [
            'green_ratio'    => [0.50, 0.95],
            'brown_ratio'    => [0.00, 0.02],
            'yellow_ratio'   => [0.00, 0.02],
            'white_ratio'    => [0.00, 0.01],
            'name_visual'    => 'Healthy (vibrant green with no disease lesions)',
        ],
    ];

    private ?array $blastDatasetMetadata = null;
    private ?array $blbDatasetMetadata = null;
    private ?array $tungroDatasetMetadata = null;
    private ?array $brownSpotDatasetMetadata = null;
    private ?array $sheathBlightDatasetMetadata = null;
    private ?array $healthyDatasetMetadata = null;

    private $diseases = [
        'blast' => [
            'name' => 'Leaf Blast',
            'scientific' => 'Magnaporthe oryzae (Pyricularia oryzae)',
            'severity' => 'severe',
            'severity_class' => 'blast-bg',
            'severity_levels' => [
                'mild' => [
                    'severity' => 'mild',
                    'range' => '≤ 25%',
                    'description' => 'Initial blast infection characterized by small brown specks or pinhead-sized diamond spots on leaf blades with mostly green intact canopy.',
                    'chemical' => [
                        ['name' => 'Tricyclazole 75% WP (Beam / Blast-Off)', 'desc' => 'Apply 0.6–1.0 g/L (300–400 g/ha) as early preventive foliar spray. DA-PhilRice standard systemic protective fungicide that inhibits fungal melanin biosynthesis.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Kasugamycin 2% SL (Kasumin)', 'desc' => 'Apply 1.5–2.0 ml/L. Systemic protective agricultural bio-fungicide with translaminar action that prevents fungal spore penetration and hyphal growth.', 'tag' => 'Bio-Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Balanced Nitrogen (Follow DA Leaf Color Chart - LCC)', 'desc' => 'Avoid excess urea application during vegetative stage. Split nitrogen fertilizer into 3-4 split applications based on LCC reading.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Maintain Continuous Shallow Water (3–5 cm)', 'desc' => 'Do not allow the paddy field to dry out during tillering. Water-stressed/dry paddies significantly heighten blast vulnerability.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Carbonized Rice Hull (CRH) / Silica Application', 'desc' => 'Apply 200–300 kg/ha CRH or calcium silicate to enrich soil silica and toughen leaf epidermal silica cells against fungal piercing.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Trichoderma harzianum (Bio-Control Agent)', 'desc' => 'Spray 5–10 g/L Trichoderma suspension on leaf canopy in late afternoon to biologically compete with fungal spores.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ],
                ],
                'moderate' => [
                    'severity' => 'moderate',
                    'range' => '26% – 60%',
                    'description' => 'Active leaf blast with distinct spindle-shaped or diamond-shaped lesions having gray/whitish necrotic centers and dark reddish-brown margins coalescing across leaf mid-ribs.',
                    'chemical' => [
                        ['name' => 'Isoprothiolane 40% EC (Fuji-One)', 'desc' => 'Apply 1.5–2.0 ml/L (750–1000 ml/ha) foliar spray. Systemic fungicide with strong translaminar and acropetal translocation that arrests active lesion expansion.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)', 'desc' => 'Apply 1.0 ml/L spray. Dual systemic strobilurin + triazole active ingredients providing curative inhibition of mycelial growth and anti-sporulant action.', 'tag' => 'Systemic Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Tebuconazole + Trifloxystrobin (Nativo 75 WG)', 'desc' => 'Apply 0.5–0.75 g/L spray. Provides broad-spectrum curative and mesostemic protection across canopy leaves.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Complete Nitrogen (Urea) Suspension', 'desc' => 'Immediately halt all topdressing of nitrogenous fertilizers until blast spots completely dry up and active sporulation ceases.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Potassium Boost (Muriate of Potash - 0-0-60)', 'desc' => 'Apply 30–40 kg/ha K₂O to strengthen cell walls and enhance plant physiological resistance against fungal enzymes.', 'tag' => 'Nutritional', 'tag_class' => 'cultural'],
                        ['name' => 'Canopy Aeration & Water Flow Management', 'desc' => 'Maintain proper spacing and avoid water stagnant overflow from infected field sections to uninfected plots.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Compost Tea & Seaweed Extract Foliar Spray', 'desc' => 'Foliar application to stimulate Systemic Acquired Resistance (SAR) and boost plant vigor.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ],
                ],
                'severe' => [
                    'severity' => 'severe',
                    'range' => '> 60%',
                    'description' => 'Advanced acute leaf blast with widespread coalesced necrotic lesions, scorched or burnt leaf appearance, collar rot, and imminent threat of neck/panicle blast.',
                    'chemical' => [
                        ['name' => 'Therapeutic Tricyclazole + Propiconazole / Mancozeb Tank Mix', 'desc' => 'Emergency therapeutic tank spray (1.5–2.0 g/L) directed at upper leaves and panicle boot to save productive tillers and prevent catastrophic neck blast.', 'tag' => 'Emergency Therapeutic', 'tag_class' => 'fungicide'],
                        ['name' => 'Carbendazim 50% WP + Epoxiconazole', 'desc' => 'Apply 1.5–2.0 g/L for rapid curative eradication of active sporulating mycelial masses.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Field Sanitation & Burning of Severely Stricken Residues', 'desc' => 'Carefully collect and burn heavily blasted crop stubbles away from paddies to eradicate overwintering conidia/spore reserves.', 'tag' => 'Sanitation', 'tag_class' => 'cultural'],
                        ['name' => 'Plant DA-PhilRice Recommended Blast-Resistant Varieties', 'desc' => 'Shift strictly next cropping season to certified resistant varieties such as NSIC Rc222, NSIC Rc160, NSIC Rc402, PSB Rc18, or Tubigan series.', 'tag' => 'Varietal Selection', 'tag_class' => 'cultural'],
                        ['name' => 'Certified Clean Seed Treatment', 'desc' => 'Source only PhilRice certified seeds and treat seeds with warm water (52–54°C for 15 mins) or bio-fungicide prior to soaking and incubation.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ],
                ],
            ],
            'treatments' => [
                'chemical' => [
                    ['name' => 'Tricyclazole 75% WP (DA-PhilRice Standard)', 'desc' => 'Apply 0.6–1.0 g/L foliar spray for systemic prevention and cure of leaf and neck blast.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ['name' => 'Isoprothiolane 40% EC (Fuji-One)', 'desc' => 'Apply 1.5–2.0 ml/L as foliar spray to arrest active mycelial expansion in leaves.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ['name' => 'Azoxystrobin + Difenoconazole', 'desc' => 'Apply 1.0 ml/L for dual-action curative and protective broad-spectrum control.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                ],
                'organic' => [
                    ['name' => 'Resistant Varieties (NSIC Rc222, NSIC Rc160)', 'desc' => 'Plant certified blast-resistant varieties recommended by DA-PhilRice and local agriculture offices.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Balanced Nitrogen & Leaf Color Chart (LCC)', 'desc' => 'Avoid excess nitrogen fertilizer. Maintain proper 3-5 cm water depth during tillering stage.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Silicon & Rice Hull Ash (CRH)', 'desc' => 'Apply 200–300 kg/ha CRH or silicate amendments to strengthen leaf cuticle against penetration.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Trichoderma harzianum', 'desc' => 'Foliar spray at 5–10 g/L in late afternoon to biologically inhibit Magnaporthe spore germination.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                ],
            ],
        ],
        'blb' => [
            'name' => 'Bacterial Leaf Blight',
            'scientific' => 'Xanthomonas oryzae pv. oryzae',
            'severity' => 'moderate',
            'severity_class' => 'blb-bg',
            'severity_levels' => [
                'mild' => [
                    'severity' => 'mild',
                    'range' => '≤ 25%',
                    'description' => 'Initial bacterial infection with small water-soaked streaks or narrow yellowish wavy margins at leaf tips. Minimal vascular blockage.',
                    'chemical' => [
                        ['name' => 'Copper Hydroxide 77% WP (Kocide 2000 / Champion)', 'dosage' => '2.0–2.5 g / L water (1.0–1.25 kg/ha)', 'desc' => 'Apply as preventive contact foliar spray to sanitize leaf surfaces and inhibit Xanthomonas entry through hydathodes.', 'tag' => 'Contact Bactericide', 'tag_class' => 'bactericide'],
                        ['name' => 'Copper Oxychloride 50% WP (Vitigran Blue / Cuprofix)', 'dosage' => '2.5–3.0 g / L water', 'desc' => 'Use during early vegetative stage. Provides an active inorganic protective copper barrier against bacterial multiplication.', 'tag' => 'Protective Bactericide', 'tag_class' => 'bactericide'],
                    ],
                    'organic' => [
                        ['name' => 'Bacillus subtilis / Pseudomonas fluorescens', 'dosage' => '5–10 g / L water (10^8 CFU/mL)', 'desc' => 'Spray late in the afternoon. Antagonistic bacteria aggressively colonize the leaf phyllosphere and suppress blight bacteria.', 'tag' => 'Bio-Bactericide', 'tag_class' => 'biological'],
                        ['name' => 'Vermicompost Tea / Fermented Plant Juice (FPJ)', 'dosage' => '1:20 dilution', 'desc' => 'Stimulates plant Systemic Acquired Resistance (SAR) and enriches beneficial leaf microflora.', 'tag' => 'Bio-Stimulant', 'tag_class' => 'biological'],
                    ],
                    'management_practices' => [
                        ['practice' => 'Intermittent Field Drainage (2–3 Days)', 'category' => 'Water Management', 'desc' => 'Drain standing water completely from the paddy for 48–72 hours to lower relative humidity within the crop canopy below 80%.'],
                        ['practice' => 'Strict Dew-Free Field Operations', 'category' => 'Cultural Sanitation', 'desc' => 'Strictly avoid walking through, weeding, or touching the crop while morning dew is on leaves to prevent mechanical bacterial transmission.'],
                        ['practice' => 'Immediate Urea / Nitrogen Suspension', 'category' => 'Nutrient Management', 'desc' => 'Temporarily suspend all topdressing of urea to prevent thin, succulent leaf tissue vulnerable to bacterial invasion.'],
                        ['practice' => 'Irrigation Water Isolation', 'category' => 'Field Quarantine', 'desc' => 'Close connecting bund inlets and outlets so irrigation water does not flow from infected paddies into neighboring uninfected rice plots.'],
                    ],
                    'agricultural_strategies' => [
                        ['strategy' => 'Potassium & Silica Cell Wall Fortification', 'category' => 'Nutrition & Resilience', 'desc' => 'Apply 30–40 kg/ha K₂O (Muriate of Potash, 0-0-60) and 200–300 kg/ha Carbonized Rice Hull (CRH) to thicken epidermal cell walls against penetration.'],
                        ['strategy' => 'Leaf Color Chart (LCC) Precision Nitrogen Scheduling', 'category' => 'Fertilizer Optimization', 'desc' => 'Measure leaf greenness with DA-PhilRice LCC. Apply nitrogen only when readings drop below panel 4 to optimize tiller count without excessive lushness.'],
                        ['strategy' => 'Alternate Wetting and Drying (AWD) Water Regime', 'category' => 'Water Efficiency', 'desc' => 'Allow water level to drop to 15 cm below soil surface before re-flooding to strengthen root anchorage and halt bacterial spread.'],
                        ['strategy' => 'Flag Leaf Preservation for High Harvest Yield', 'category' => 'Yield Protection', 'desc' => 'Intervening early keeps blight below 25%, preserving over 75% of grain filling capacity and securing full potential harvest yield (5.5–6.5 t/ha).'],
                    ],
                    'productivity_harvest_impact' => 'Mild infection affects ≤ 25% leaf area. Early treatment preserves 85–95% canopy photosynthesis, protecting full harvest yield potential (5.5–6.5 tons/ha).',
                ],
                'moderate' => [
                    'severity' => 'moderate',
                    'range' => '26% – 60%',
                    'description' => 'Active bacterial blight with noticeable wavy yellow-orange margins expanding along leaf blades and drying tips.',
                    'chemical' => [
                        ['name' => 'Streptomycin Sulfate + Oxytetracycline (Plantomycin / Agrimycin 100)', 'dosage' => '150–200 ppm (1.5–2.0 g / L water)', 'desc' => 'Systemic agricultural antibiotic that penetrates vascular bundles to arrest bacterial replication. Repeat after 7–10 days.', 'tag' => 'Systemic Antibiotic', 'tag_class' => 'bactericide'],
                        ['name' => 'Zinc Thiazole / Bismerthiazol 20% SC', 'dosage' => '1.5–2.0 mL / L water (750–1000 mL/ha)', 'desc' => 'Highly effective systemic bactericide specifically targeting Xanthomonas bacterial cells with zero phytotoxicity.', 'tag' => 'Targeted Bactericide', 'tag_class' => 'bactericide'],
                        ['name' => 'Kasugamycin 2% + Copper Oxychloride (Kasumin-B)', 'dosage' => '2.0 mL / L water', 'desc' => 'Provides synergistic translaminar systemic action and broad-spectrum contact protection to halt active lesion expansion.', 'tag' => 'Translaminar Bactericide', 'tag_class' => 'bactericide'],
                    ],
                    'organic' => [
                        ['name' => 'Paenibacillus polymyxa / Bacillus amyloliquefaciens', 'dosage' => '5–10 g / L water', 'desc' => 'Late afternoon spray following drainage to induce plant defense enzymes and suppress bacterial spread.', 'tag' => 'Bio-Bactericide', 'tag_class' => 'biological'],
                        ['name' => 'Pyroligneous Acid (Wood Vinegar 1:300)', 'dosage' => '3–4 mL / L water', 'desc' => 'Natural organic bactericide spray to acidify leaf phyllosphere and suppress bacterial ooze.', 'tag' => 'Organic Bactericide', 'tag_class' => 'biological'],
                    ],
                    'management_practices' => [
                        ['practice' => 'Complete Nitrogen (Urea) Suspension', 'category' => 'Nutrient Management', 'desc' => 'Completely cease all nitrogen topdressing until lesion margins dry up, turn grayish-white, and stop oozing.'],
                        ['practice' => 'Paddy Drainage & Canopy Aeration (3–4 Days)', 'category' => 'Water Management', 'desc' => 'Drain floodwater from the field for 3 to 4 days until soil exhibits hairline cracks to stop bacterial swimming mobility.'],
                        ['practice' => 'Strict Field Hygiene & Disinfection', 'category' => 'Sanitation', 'desc' => 'Disinfect footgear and tools with 5% bleach or disinfectant when moving from affected plots to unaffected fields.'],
                        ['practice' => 'Bund Weed Clearance (Rogue Host Removal)', 'category' => 'Host Eradication', 'desc' => 'Clear wild host grasses (Leersia oryzoides, Echinochloa) along paddy dikes that harbor Xanthomonas bacteria.'],
                    ],
                    'agricultural_strategies' => [
                        ['strategy' => 'Panicle Initiation Potassium Booster (0-0-60 Topdress)', 'category' => 'Nutrient Compensation', 'desc' => 'Apply 20–25 kg/ha K₂O (MOP) at panicle initiation to enhance carbohydrate translocation and reduce chaffy grains.'],
                        ['strategy' => 'Foliar Zinc & Micronutrient Recovery Spray', 'category' => 'Metabolic Support', 'desc' => 'Apply chelated zinc (0.5% ZnSO₄) and boron foliar spray to preserve pollen viability and promote uniform panicle exertion.'],
                        ['strategy' => 'Canopy Micro-Climate Moderation via AWD', 'category' => 'Disease Suppression', 'desc' => 'Practice controlled AWD to eliminate continuous standing water films on lower leaf sheaths, breaking the disease cycle.'],
                        ['strategy' => 'Productivity Recovery & Yield Salvage Strategy', 'category' => 'Harvest Protection', 'desc' => 'Arresting moderate blight at 26–60% preserves upper canopy photosynthesis, salvaging up to 80–85% of standard crop harvest.'],
                    ],
                    'productivity_harvest_impact' => 'Moderate infection affects 26–60% leaf area. Rapid antibiotic/bactericide spray and AWD drainage halt lesion spread, salvaging 80–85% of standard harvest yield.',
                ],
                'severe' => [
                    'severity' => 'severe',
                    'range' => '> 60%',
                    'description' => 'Advanced blight condition (Kresek / systemic wilt) with large bleached-gray or straw-colored dried leaves and severe photosynthetic impairment.',
                    'chemical' => [
                        ['name' => 'Therapeutic Streptomycin-Oxytetracycline (200 ppm) + Zinc Thiazole 20% SC Tank Mix', 'dosage' => '2.0–2.5 g + 1.5 mL / L water', 'desc' => 'Emergency curative spray directed forcefully at remaining green foliage and flag leaf boots to salvage productive tillers.', 'tag' => 'Emergency Therapeutic', 'tag_class' => 'bactericide'],
                        ['name' => 'Copper Hydroxide 77% WP + Propiconazole (Dual Bactericide-Fungicide)', 'dosage' => '2.5 g + 1.0 mL / L water', 'desc' => 'Sanitizes collapsing leaf tissues and suppresses opportunistic secondary pathogens (sheath rot, blast).', 'tag' => 'Broad-Spectrum Therapy', 'tag_class' => 'bactericide'],
                    ],
                    'organic' => [
                        ['name' => 'Trichoderma harzianum + Effective Microorganisms (EM-1)', 'dosage' => '500 mL / 100 L water applied to soil during drainage', 'desc' => 'Biologically digests infected crop debris and suppresses soil-borne bacterial survivability ahead of the next cycle.', 'tag' => 'Soil Bio-Restoration', 'tag_class' => 'biological'],
                    ],
                    'management_practices' => [
                        ['practice' => 'Deep Soil Sun-Drying & Complete Aeration', 'category' => 'Field Rehabilitation', 'desc' => 'Completely drain standing water and allow soil to dry thoroughly for 5–7 days. Solar UV radiation sanitizes surface bacterial ooze.'],
                        ['practice' => 'Rogueing & Safe Destruction of Kresek Tillers', 'category' => 'Inoculum Reduction', 'desc' => 'Carefully uproot and remove completely wilted, bleached-white tillers exhibiting kresek collapse; dispose of away from waterways.'],
                        ['practice' => 'Post-Harvest Deep Moldboard Plowing (20–25 cm)', 'category' => 'Sanitation & Inter-Season Hygiene', 'desc' => 'Plow under all crop stubble and infected straw immediately after harvest to an inversion depth of 20–25 cm.'],
                    ],
                    'agricultural_strategies' => [
                        ['strategy' => 'Strict Switch to PhilRice BLB-Resistant Rice Varieties Next Season', 'category' => 'Varietal Selection (Crucial)', 'desc' => 'Next cropping season, transition strictly to certified varieties with proven multi-gene resistance (Xa4, Xa7, Xa21) such as NSIC Rc152, PSB Rc82, NSIC Rc222, or IRBB21.'],
                        ['strategy' => 'Certified Clean Seed Sourcing & Hot Water Seed Treatment', 'category' => 'Seed Health', 'desc' => 'Purchase certified blue-tag seeds. Submerge seeds in warm water (52–54°C) for 15 minutes before soaking to eradicate seed-borne inoculum.'],
                        ['strategy' => 'Pre-Planting Basal Silicate & Organic Conditioning', 'category' => 'Soil Regeneration', 'desc' => 'Incorporate 2–3 tons/ha decomposed manure/compost plus 300 kg/ha Carbonized Rice Hull (CRH) during land preparation.'],
                        ['strategy' => 'Salvaging Grain Filling in Surviving Tillers', 'category' => 'Harvest Protection', 'desc' => 'Apply light potassium topdressing (15 kg K₂O/ha) to surviving tillers to optimize panicle filling, minimizing catastrophic crop failure.'],
                    ],
                    'productivity_harvest_impact' => 'Severe infection affects > 60% leaf area (advanced kresek/wilt). Emergency therapeutic intervention salvages remaining tillers; immediate switch to resistant varieties next season ensures sustained productivity.',
                ],
            ],
            'treatments' => [
                'chemical' => [
                    ['name' => 'Streptomycin Sulfate + Oxytetracycline (150-200 ppm)', 'desc' => 'Apply 1.5-2.0 g/L as systemic foliar spray. Repeat every 7-10 days until bacterial replication is arrested.', 'tag' => 'Bactericide', 'tag_class' => 'bactericide'],
                    ['name' => 'Zinc Thiazole 20% SC', 'desc' => 'Apply 1.5-2.0 ml/L. Highly effective bactericide with systemic translocation specifically targeting Xanthomonas.', 'tag' => 'Bactericide', 'tag_class' => 'bactericide'],
                    ['name' => 'Copper Hydroxide 77% WP', 'desc' => 'Apply 2-2.5 g/L as contact foliar spray to sanitize leaf surfaces and prevent hydathode penetration.', 'tag' => 'Bactericide', 'tag_class' => 'bactericide'],
                ],
                'organic' => [
                    ['name' => 'Resistant Varieties with Xa Genes (NSIC Rc152, PSB Rc82)', 'desc' => 'Plant certified varieties with Xa4, Xa7, or Xa21 resistance genes recommended by DA-PhilRice.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Field Drainage & Moisture Reduction', 'desc' => 'Drain standing water from paddy for 2-3 days to lower relative humidity below 80% and halt bacterial spread.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Pseudomonas fluorescens / Bacillus subtilis', 'desc' => 'Antagonistic bacteria foliar spray at 5-10 g/L to naturally colonize leaf phyllosphere.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ['name' => 'Balanced Fertilization & Potassium (MOP 0-0-60)', 'desc' => 'Halt excessive nitrogen. Apply potassium (30-40 kg K2O/ha) to improve leaf cell wall resistance.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                ],
            ],
        ],
        'brown_spot' => [
            'name' => 'Brown Spot',
            'scientific' => 'Bipolaris oryzae (Cochliobolus miyabeanus / Helminthosporium oryzae)',
            'severity' => 'moderate',
            'severity_class' => 'blb-bg',
            'severity_levels' => [
                'mild' => [
                    'severity' => 'mild',
                    'range' => '≤ 25%',
                    'description' => 'Early infection with isolated small, circular, pinhead-sized dark brown spots on leaf blades with minimal chlorosis, indicating initial soil nutrient stress.',
                    'chemical' => [
                        ['name' => 'Mancozeb 80% WP (Dithane M-45)', 'desc' => 'Apply 2.0–2.5 g/L (1.5–2.0 kg/ha) as early protective contact foliar spray. Creates an active surface barrier preventing fungal spore germination on leaf tissue.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Propiconazole 25% EC (Tilt / Bumper)', 'desc' => 'Apply 0.75–1.0 ml/L. Systemic protective triazole fungicide with acropetal translocation that stops early fungal hyphal establishment.', 'tag' => 'Systemic Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Soil Nutrient Correction & Potash (MOP 0-0-60)', 'desc' => 'Brown spot is primarily an indicator of nutrient-deficient / unfertile soil. Apply 30–40 kg/ha Muriate of Potash (K₂O) to restore plant physiological resistance.', 'tag' => 'Nutritional', 'tag_class' => 'cultural'],
                        ['name' => 'Zinc Sulfate (ZnSO₄) Soil/Foliar Amendment', 'desc' => 'Apply 20–25 kg/ha Zinc Sulfate at basal or 0.5% foliar spray to correct zinc deficiency which predisposes rice to brown spot.', 'tag' => 'Nutritional', 'tag_class' => 'cultural'],
                        ['name' => 'Organic Compost & Decomposed Farmyard Manure', 'desc' => 'Incorporate 2–3 tons/ha well-decomposed organic matter/compost to enhance soil cation exchange capacity (CEC) and moisture retention.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Trichoderma harzianum / Bacillus subtilis', 'desc' => 'Apply 5–10 g/L bio-fungicide foliar spray in late afternoon to biologically outcompete Bipolaris fungal spores.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ],
                ],
                'moderate' => [
                    'severity' => 'moderate',
                    'range' => '26% – 60%',
                    'description' => 'Moderate Brown Spot infection with numerous round-to-oval chocolate-brown spots having grayish centers and bright yellow chlorotic halos coalescing across leaf blades.',
                    'chemical' => [
                        ['name' => 'Tebuconazole 250 EC (Folicur)', 'desc' => 'Apply 0.75–1.0 ml/L (500–750 ml/ha) foliar spray. Systemic fungicide providing curative and translaminar control against expanding Bipolaris lesions.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)', 'desc' => 'Apply 1.0 ml/L spray. Dual strobilurin + triazole systemic formulation providing curative and anti-sporulant action.', 'tag' => 'Systemic Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Hexaconazole 5% SC / 5% EC (Contaf)', 'desc' => 'Apply 1.5–2.0 ml/L spray to inhibit ergosterol biosynthesis and dry up spreading leaf spot colonies.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Split Potassium & Balanced Nitrogen Topdressing', 'desc' => 'Avoid excess urea; apply balanced N-P-K with topdress potassium (15–20 kg K₂O/ha at panicle initiation) to strengthen leaf tissue.', 'tag' => 'Nutritional', 'tag_class' => 'cultural'],
                        ['name' => 'Micronutrient Foliar Boost (Zinc + Manganese + Silicon)', 'desc' => 'Spray chelated micronutrient solution + liquid potassium silicate to quickly alleviate physiological leaf starvation.', 'tag' => 'Nutritional', 'tag_class' => 'cultural'],
                        ['name' => 'Intermittent Irrigation & Aeration', 'desc' => 'Practice Alternate Wetting and Drying (AWD); avoid severe soil drying cracking which causes root damage and nutrient uptake arrest.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Neem Seed Kernel Extract (NSKE 5%)', 'desc' => 'Spray 5% neem extract to act as natural anti-fungal repellent and plant tonic.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ],
                ],
                'severe' => [
                    'severity' => 'severe',
                    'range' => '> 60%',
                    'description' => 'Severe acute Brown Spot with widespread coalesced necrotic lesions, premature leaf drying, severe photosynthetic failure, and active glume infection (pecky rice / dark grain discoloration).',
                    'chemical' => [
                        ['name' => 'Therapeutic Propiconazole + Difenoconazole / Mancozeb Tank Mix', 'desc' => 'Emergency curative spray (1.5–2.0 g/L) directed at upper canopy and emerging panicles to save productive tillers and protect grains from pecky rice / seed rot.', 'tag' => 'Emergency Therapeutic', 'tag_class' => 'fungicide'],
                        ['name' => 'Carbendazim 50% WP + Tebuconazole', 'desc' => 'Apply 1.5–2.0 g/L for rapid curative eradication of sporulating Bipolaris colonies.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Plant DA-PhilRice Recommended Tolerant Varieties Next Season', 'desc' => 'Shift strictly next season to certified varieties with proven tolerance to low-fertility soils and Brown Spot (NSIC Rc216, NSIC Rc222, PSB Rc14, NSIC Rc128).', 'tag' => 'Varietal Selection', 'tag_class' => 'cultural'],
                        ['name' => 'Hot Water Seed Treatment (Seed Disinfection)', 'desc' => 'Treat certified seeds in hot water (52–54°C for 15 mins) before pre-germination to eradicate seed-borne Bipolaris oryzae mycelia.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Post-Harvest Soil Reclamation & Deep Plowing', 'desc' => 'Deep-plow crop stubble and incorporate 200–300 kg/ha agricultural lime (if soil is acidic) plus 2 t/ha compost to revitalize soil biology.', 'tag' => 'Sanitation', 'tag_class' => 'cultural'],
                    ],
                ],
            ],
            'treatments' => [
                'chemical' => [
                    ['name' => 'Propiconazole (Fungicide)', 'desc' => 'Apply 1 ml/L of water as foliar spray. Repeat after 10-14 days if necessary. Effective against fungal leaf spots.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ['name' => 'Mancozeb (Contact Fungicide)', 'desc' => 'Use 2-2.5 g/L as protective spray. Apply at maximum tillering and booting stages.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ['name' => 'Tebuconazole (Systemic)', 'desc' => 'Apply 0.75-1 ml/L. Provides both curative and protective action against brown spot.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                ],
                'organic' => [
                    ['name' => 'Resistant Varieties', 'desc' => 'Use tolerant varieties like PSB Rc14, NSIC Rc128, or varieties recommended for low-fertility soils.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Soil Nutrient Management', 'desc' => 'Correct soil nutrient deficiencies, especially potassium and manganese. Apply 20-30 kg K2O/ha.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Bacillus subtilis', 'desc' => 'Apply as foliar spray at 5-10 g/L. Antagonistic bacterium suppresses fungal pathogens.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ['name' => 'Neem Seed Extract', 'desc' => 'Use 5% neem seed kernel extract as foliar spray every 7-10 days at first sign of infection.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                ],
            ],
        ],
        'tungro' => [
            'name' => 'Rice Tungro Disease',
            'scientific' => 'Rice Tungro Bacilliform Virus (RTBV) + Rice Tungro Spherical Virus (RTSV)',
            'severity' => 'severe',
            'severity_class' => 'blast-bg',
            'severity_levels' => [
                'mild' => [
                    'severity' => 'mild',
                    'range' => '≤ 25%',
                    'description' => 'Early-stage viral infection with initial light yellowing of upper leaf tips and minor vector feeding marks. Minimal height reduction.',
                    'chemical' => [
                        ['name' => 'Imidacloprid 17.8% SL', 'desc' => 'Apply 0.5–0.75 ml/L foliar spray. Rapid systemic knockdown of Green Leafhopper (Nephotettix virescens) vectors before viral inoculation.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                        ['name' => 'Thiamethoxam 25% WG', 'desc' => 'Apply 0.2–0.3 g/L as systemic neonicotinoid protective vector barrier across field borders.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                    ],
                    'organic' => [
                        ['name' => 'Synchronous Community Planting', 'desc' => 'Coordinate community planting within a 2-week window to break the continuous insect vector breeding cycle.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Yellow Sticky Vector Traps', 'desc' => 'Install 20–25 yellow sticky insect traps per hectare to monitor and trap green leafhoppers.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Potassium & Zinc Nutrition', 'desc' => 'Apply Muriate of Potash (30–40 kg K₂O/ha) and Zinc Sulfate (25 kg/ha) to fortify plant vascular health.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ],
                ],
                'moderate' => [
                    'severity' => 'moderate',
                    'range' => '26% – 60%',
                    'description' => 'Moderate Tungro infection with pronounced yellow-orange leaf discoloration extending from leaf tip to blade, mottled green patches, and noticeable plant stunting with reduced tillering.',
                    'chemical' => [
                        ['name' => 'Dinotefuran 20% SG', 'desc' => 'Apply 0.5–1.0 g/L. Fast-acting 3rd-generation neonicotinoid with systemic and translaminar activity against leafhopper nymphs and adults.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                        ['name' => 'Clothianidin + Pymetrozine', 'desc' => 'Apply 1.0 g/L. Paralyzes insect feeding mouthparts and arrests vector transmission immediately.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                        ['name' => 'Buprofezin 25% SC', 'desc' => 'Apply 1.5–2.0 ml/L. Insect growth regulator (IGR) that inhibits nymphal molting of leafhopper vectors.', 'tag' => 'IGR', 'tag_class' => 'bactericide'],
                    ],
                    'organic' => [
                        ['name' => 'Selective Rogueing', 'desc' => 'Uproot and bury individual severely yellowed hills displaying distinct stunting to reduce field viral inoculum sources.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Neem Seed Kernel Extract (NSKE 5%)', 'desc' => 'Spray 5% neem extract to act as an antifeedant and oviposition deterrent against vectors.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                        ['name' => 'Water Management', 'desc' => 'Maintain shallow water depth (2–3 cm) to hinder leafhopper nymph movement between tillers.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ],
                ],
                'severe' => [
                    'severity' => 'severe',
                    'range' => '> 60%',
                    'description' => 'Severe systemic Tungro condition with intense orange-yellow discoloration, severe stunting, compact tillers, failure of panicle emergence or sterile partial panicles.',
                    'chemical' => [
                        ['name' => 'Etofenprox 10% EC', 'desc' => 'Apply 1.5–2.0 ml/L pyrethroid ether for rapid emergency knockdown of high-density leafhopper populations.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                        ['name' => 'Fipronil 5% SC / Clothianidin 50% WDG', 'desc' => 'Emergency vector eradication directed at the base and foliage of the crop.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                    ],
                    'organic' => [
                        ['name' => 'Systemic Rogueing & Field Sanitation', 'desc' => 'Pull out completely stunted, unheading hills and burn away from the field.', 'tag' => 'Sanitation', 'tag_class' => 'cultural'],
                        ['name' => 'Foliar Micronutrient & Amino Acid Boost', 'desc' => 'Spray liquid potassium silicate + seaweed extract to salvage productive border tillers.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Switch to Resistant Varieties Next Season', 'desc' => 'Plant certified Tungro-resistant rice varieties (NSIC Rc160, NSIC Rc120, PSB Rc10, IR64-Sub1, or Matatag lines) in the following cropping season.', 'tag' => 'Varietal Selection', 'tag_class' => 'cultural'],
                        ['name' => 'Fallow Period & Deep Plowing', 'desc' => 'Implement a strict 30-day crop-free fallow period after harvest and deep-plow all ratoon growths to eliminate overwintering RTBV/RTSV viral reservoirs.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ],
                ],
            ],
            'treatments' => [
                'chemical' => [
                    ['name' => 'Imidacloprid 17.8% SL', 'desc' => 'Apply 0.5-1 ml/L to control green leafhopper (GLH) vectors before viral transmission.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                    ['name' => 'Dinotefuran 20% SG', 'desc' => 'Apply 0.5-1.0 g/L for fast systemic control of vector leafhoppers.', 'tag' => 'Insecticide', 'tag_class' => 'bactericide'],
                ],
                'organic' => [
                    ['name' => 'Tungro-Resistant Seed Varieties', 'desc' => 'Plant NSIC Rc160, PSB Rc10, or Matatag certified resistant rice seeds.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Synchronous Community Planting', 'desc' => 'Coordinate planting across neighboring paddies within a 2-week window.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Rogue Out Infected Hills', 'desc' => 'Uproot and burn yellowed infected hills immediately upon early detection.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                ],
            ],
        ],
        'sheath_blight' => [
            'name' => 'Sheath Blight',
            'scientific' => 'Rhizoctonia solani (Thanatephorus cucumeris)',
            'severity' => 'moderate',
            'severity_class' => 'blb-bg',
            'severity_levels' => [
                'mild' => [
                    'severity' => 'mild',
                    'range' => '≤ 25%',
                    'description' => 'Early infection with isolated oval, greenish-gray water-soaked spots on leaf sheaths just above the water line.',
                    'chemical' => [
                        ['name' => 'Validamycin 3% L (Sheathmar / Validacin)', 'desc' => 'Apply 2.0–2.5 ml/L foliar spray targeted at the lower canopy/culm base. Highly effective antibiotic fungicide that halts Rhizoctonia hyphal elongation.', 'tag' => 'Bio-Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Hexaconazole 5% SC / 5% EC (Contaf)', 'desc' => 'Apply 1.5–2.0 ml/L spray to arrest mycelial growth on lower leaf sheaths.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Canopy Aeration & Plant Spacing', 'desc' => 'Maintain proper plant spacing (20x20 cm) to improve sunlight penetration and air circulation across the lower culm.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Balanced Nitrogen & LCC Monitoring', 'desc' => 'Avoid excessive dense vegetative canopy caused by over-fertilizing with Urea.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Trichoderma viride / harzianum', 'desc' => 'Apply antagonistic bio-agent foliar/soil drench at 5–10 g/L to biologically suppress Rhizoctonia sclerotia.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                        ['name' => 'Potassium & Silicon Amendment', 'desc' => 'Apply Muriate of Potash (30–40 kg K₂O/ha) and Carbonized Rice Hull (CRH) to toughen sheath epidermal cell walls.', 'tag' => 'Nutritional', 'tag_class' => 'cultural'],
                    ],
                ],
                'moderate' => [
                    'severity' => 'moderate',
                    'range' => '26% – 60%',
                    'description' => 'Active sheath blight with characteristic irregular ellipsoid snake-skin lesions having bleached centers and dark reddish-brown margins ascending to middle and upper leaf sheaths.',
                    'chemical' => [
                        ['name' => 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)', 'desc' => 'Apply 1.0 ml/L spray directed at mid-canopy. Dual systemic strobilurin + triazole with powerful translaminar curative action.', 'tag' => 'Systemic Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Thifluzamide 24% SC (Pulsor)', 'desc' => 'Apply 0.75–1.0 ml/L. Highly potent succinate dehydrogenase inhibitor (SDHI) fungicide specifically active against Rhizoctonia sheath blight.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                        ['name' => 'Propiconazole 25% EC (Tilt)', 'desc' => 'Apply 1.0 ml/L foliar spray to halt lesion ascension up the rice tiller.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Alternate Wetting and Drying (AWD)', 'desc' => 'Drain field water intermittently for 2–3 days to reduce relative humidity inside the crop canopy.', 'tag' => 'Water Management', 'tag_class' => 'cultural'],
                        ['name' => 'Complete Urea Suspension', 'desc' => 'Halt all topdress nitrogen applications immediately to prevent succulent tissue expansion.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Neem Seed Kernel Extract (NSKE 5%)', 'desc' => 'Spray 5% neem extract to act as natural anti-fungal repellent and plant tonic.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ],
                ],
                'severe' => [
                    'severity' => 'severe',
                    'range' => '> 60%',
                    'description' => 'Advanced sheath blight lesions reaching the flag leaf sheath and leaf blades, causing extensive lodging, tiller death, poor grain filling, and sclerotial formation.',
                    'chemical' => [
                        ['name' => 'Therapeutic Thifluzamide + Tebuconazole / Epoxiconazole Tank Mix', 'desc' => 'Emergency therapeutic spray (1.5–2.0 g/L) to salvage flag leaves and booting panicles from catastrophic lodging and blighting.', 'tag' => 'Emergency Therapeutic', 'tag_class' => 'fungicide'],
                        ['name' => 'Carbendazim 50% WP + Difenoconazole', 'desc' => 'Apply 1.5–2.0 g/L for rapid curative eradication of active ascending fungal colonies.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ],
                    'organic' => [
                        ['name' => 'Destroy Stricken Stubbles & Floating Sclerotia', 'desc' => 'Skim floating sclerotia during final land leveling and burn severely infected crop residues after harvest.', 'tag' => 'Sanitation', 'tag_class' => 'cultural'],
                        ['name' => 'Strict 30-Day Fallow Period & Deep Plowing', 'desc' => 'Deep-plow stubble to bury sclerotia at least 15 cm deep where they lose viability.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                        ['name' => 'Plant Tolerant Varieties Next Cropping', 'desc' => 'Shift to certified erect-leaf, moderate-tillering tolerant varieties (NSIC Rc222, NSIC Rc216, PSB Rc14).', 'tag' => 'Varietal Selection', 'tag_class' => 'cultural'],
                    ],
                ],
            ],
            'treatments' => [
                'chemical' => [
                    ['name' => 'Validamycin 3% L', 'desc' => 'Apply 2.0-2.5 ml/L foliar spray directed at the base and lower leaf sheaths.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ['name' => 'Thifluzamide 24% SC', 'desc' => 'Apply 0.75-1.0 ml/L. Highly effective fungicide specific against Rhizoctonia sheath blight.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                    ['name' => 'Azoxystrobin + Difenoconazole', 'desc' => 'Apply 1.0 ml/L for combined curative and protective control across mid-canopy.', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                ],
                'organic' => [
                    ['name' => 'Trichoderma viride / harzianum', 'desc' => 'Apply 5-10 g/L foliar spray or soil application to biologically compete with fungal sclerotia.', 'tag' => 'Biological', 'tag_class' => 'biological'],
                    ['name' => 'Alternate Wetting and Drying (AWD)', 'desc' => 'Drain standing water intermittently to lower canopy humidity and slow fungal spread.', 'tag' => 'Water Management', 'tag_class' => 'cultural'],
                    ['name' => 'Balanced Nitrogen & Proper Spacing', 'desc' => 'Maintain 20x20 cm spacing and avoid excess nitrogen fertilizer to prevent dense microclimate.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                ],
            ],
        ],
        'healthy' => [
            'name' => 'Healthy',
            'scientific' => null,
            'severity' => 'healthy',
            'severity_class' => 'healthy-bg',
            'treatments' => [
                'chemical' => [
                    ['name' => 'Preventive Maintenance', 'desc' => 'Regular preventive spraying of mild bio-fungicides during high-risk periods (wet season, high humidity).', 'tag' => 'Fungicide', 'tag_class' => 'fungicide'],
                ],
                'organic' => [
                    ['name' => 'Good Agricultural Practices', 'desc' => 'Maintain proper spacing, balanced fertilization, clean irrigation water, and field sanitation to keep plants healthy.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                    ['name' => 'Compost Application', 'desc' => 'Apply well-decomposed organic matter at 2-3 tons/ha to improve soil health and plant resistance.', 'tag' => 'Cultural', 'tag_class' => 'cultural'],
                ],
            ],
        ],
    ];

    private function readDatasetMetadataCache(string $key): ?array
    {
        try {
            $cached = Cache::get($key);
            return is_array($cached) ? $cached : null;
        } catch (\Throwable $e) {
            Log::debug('Dataset metadata cache read failed: ' . $e->getMessage());
            return null;
        }
    }

    private function writeDatasetMetadataCache(string $key, array $metadata): void
    {
        try {
            Cache::put($key, $metadata, now()->addMinutes(15));
        } catch (\Throwable $e) {
            Log::debug('Dataset metadata cache write failed: ' . $e->getMessage());
        }
    }

    private function getBlbDatasetMetadata(): array
    {
        if ($this->blbDatasetMetadata !== null) {
            return $this->blbDatasetMetadata;
        }

        $path = file_exists(storage_path('app/datasets/blb_dataset_metadata.json'))
            ? storage_path('app/datasets/blb_dataset_metadata.json')
            : base_path('storage/app/datasets/blb_dataset_metadata.json');
        $cacheKey = 'oryzatix.dataset_metadata.blb.' . (file_exists($path) ? filemtime($path) : 'missing');
        $cached = $this->readDatasetMetadataCache($cacheKey);
        if ($cached !== null) {
            return $this->blbDatasetMetadata = $cached;
        }

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $this->blbDatasetMetadata = $data['images'] ?? [];
        } else {
            $this->blbDatasetMetadata = [];
        }

        // Auto-index any newly placed BLB images in dataset folders
        $blbFolders = [
            base_path('Bacterial Leaf Blight/orginal'),
            base_path('Bacterial Leaf Blight/augmented'),
            base_path('Bacterial Leaf Blight'),
            storage_path('app/dataset/train/blb'),
            storage_path('app/dataset/val/blb'),
            storage_path('app/dataset/test/blb'),
            storage_path('app/dataset/blb'),
        ];

        foreach ($blbFolders as $folder) {
            if (is_dir($folder)) {
                $files = glob("$folder/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
                foreach ($files as $file) {
                    $fn = basename($file);
                    if (!isset($this->blbDatasetMetadata[$fn])) {
                        $this->blbDatasetMetadata[$fn] = [
                            'filename' => $fn,
                            'disease' => 'blb',
                            'severity' => 'unassessed',
                            'affected_percentage' => null,
                            'confidence' => null,
                            'md5' => md5_file($file),
                            'filesize' => filesize($file),
                        ];
                    }
                }
            }
        }

        $this->writeDatasetMetadataCache($cacheKey, $this->blbDatasetMetadata);
        return $this->blbDatasetMetadata;
    }

    private function getTungroDatasetMetadata(): array
    {
        if ($this->tungroDatasetMetadata !== null) {
            return $this->tungroDatasetMetadata;
        }

        $path = file_exists(storage_path('app/datasets/tungro_dataset_metadata.json'))
            ? storage_path('app/datasets/tungro_dataset_metadata.json')
            : base_path('storage/app/datasets/tungro_dataset_metadata.json');
        $cacheKey = 'oryzatix.dataset_metadata.tungro.' . (file_exists($path) ? filemtime($path) : 'missing');
        $cached = $this->readDatasetMetadataCache($cacheKey);
        if ($cached !== null) {
            return $this->tungroDatasetMetadata = $cached;
        }

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $this->tungroDatasetMetadata = $data['images'] ?? [];
        } else {
            $this->tungroDatasetMetadata = [];
        }

        // Auto-index any newly placed Tungro images in dataset folders
        $tungroFolders = [
            storage_path('app/dataset/train/tungro'),
            storage_path('app/dataset/val/tungro'),
            storage_path('app/dataset/test/tungro'),
            storage_path('app/dataset/tungro'),
            base_path('Tungro'),
            base_path('Tungro/Rice Tungro'),
        ];

        foreach ($tungroFolders as $folder) {
            if (is_dir($folder)) {
                $files = glob("$folder/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
                foreach ($files as $file) {
                    $fn = basename($file);
                    if (!isset($this->tungroDatasetMetadata[$fn])) {
                        $this->tungroDatasetMetadata[$fn] = [
                            'filename' => $fn,
                            'disease' => 'tungro',
                            'severity' => 'unassessed',
                            'affected_percentage' => null,
                            'confidence' => null,
                            'md5' => md5_file($file),
                            'filesize' => filesize($file),
                        ];
                    }
                }
            }
        }

        $this->writeDatasetMetadataCache($cacheKey, $this->tungroDatasetMetadata);
        return $this->tungroDatasetMetadata;
    }

    private function getBlastDatasetMetadata(): array
    {
        if ($this->blastDatasetMetadata !== null) {
            return $this->blastDatasetMetadata;
        }

        $path = file_exists(storage_path('app/datasets/blast_dataset_metadata.json'))
            ? storage_path('app/datasets/blast_dataset_metadata.json')
            : base_path('storage/app/datasets/blast_dataset_metadata.json');
        $cacheKey = 'oryzatix.dataset_metadata.blast.' . (file_exists($path) ? filemtime($path) : 'missing');
        $cached = $this->readDatasetMetadataCache($cacheKey);
        if ($cached !== null) {
            return $this->blastDatasetMetadata = $cached;
        }

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $this->blastDatasetMetadata = $data['images'] ?? [];
        } else {
            $this->blastDatasetMetadata = [];
        }

        // Auto-index any newly placed Blast images in dataset folders
        $blastFolders = [
            base_path('Brown Spot/BROWN_SPOT'),
            base_path('Brown Spot'),
            storage_path('app/dataset/train/blast'),
            storage_path('app/dataset/val/blast'),
            storage_path('app/dataset/test/blast'),
            storage_path('app/dataset/blast'),
            storage_path('app/dataset/test/brown_spot'),
        ];

        foreach ($blastFolders as $folder) {
            if (is_dir($folder)) {
                $files = glob("$folder/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
                $files = array_filter($files, fn($p) => stripos(basename($p), 'blast') !== false);
                foreach ($files as $file) {
                    $fn = basename($file);
                    if (!isset($this->blastDatasetMetadata[$fn])) {
                        $this->blastDatasetMetadata[$fn] = [
                            'filename' => $fn,
                            'disease' => 'blast',
                            'severity' => 'unassessed',
                            'affected_percentage' => null,
                            'confidence' => null,
                            'md5' => md5_file($file),
                            'filesize' => filesize($file),
                        ];
                    }
                }
            }
        }

        $this->writeDatasetMetadataCache($cacheKey, $this->blastDatasetMetadata);
        return $this->blastDatasetMetadata;
    }

    private function getBrownSpotDatasetMetadata(): array
    {
        if ($this->brownSpotDatasetMetadata !== null) {
            return $this->brownSpotDatasetMetadata;
        }

        $path = file_exists(storage_path('app/datasets/brown_spot_dataset_metadata.json'))
            ? storage_path('app/datasets/brown_spot_dataset_metadata.json')
            : base_path('storage/app/datasets/brown_spot_dataset_metadata.json');
        $cacheKey = 'oryzatix.dataset_metadata.brown_spot.' . (file_exists($path) ? filemtime($path) : 'missing');
        $cached = $this->readDatasetMetadataCache($cacheKey);
        if ($cached !== null) {
            return $this->brownSpotDatasetMetadata = $cached;
        }

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $this->brownSpotDatasetMetadata = $data['images'] ?? [];
        } else {
            $this->brownSpotDatasetMetadata = [];
        }

        // Auto-index any newly placed Brown Spot images in dataset folders
        $brownSpotFolders = [
            storage_path('app/dataset/train/brown_spot'),
            storage_path('app/dataset/val/brown_spot'),
            storage_path('app/dataset/test/brown_spot'),
            storage_path('app/dataset/brown_spot'),
            base_path('Brown Spot'),
            base_path('Brown Spot/BROWN_SPOT'),
        ];

        foreach ($brownSpotFolders as $folder) {
            if (is_dir($folder)) {
                $files = glob("$folder/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
                $files = array_filter($files, fn($p) => stripos(basename($p), 'blast') === false);
                foreach ($files as $file) {
                    $fn = basename($file);
                    if (!isset($this->brownSpotDatasetMetadata[$fn])) {
                        $this->brownSpotDatasetMetadata[$fn] = [
                            'filename' => $fn,
                            'disease' => 'brown_spot',
                            'severity' => 'unassessed',
                            'affected_percentage' => null,
                            'confidence' => null,
                            'md5' => md5_file($file),
                            'filesize' => filesize($file),
                        ];
                    }
                }
            }
        }

        $this->writeDatasetMetadataCache($cacheKey, $this->brownSpotDatasetMetadata);
        return $this->brownSpotDatasetMetadata;
    }

    private function getHealthyDatasetMetadata(): array
    {
        if ($this->healthyDatasetMetadata !== null) {
            return $this->healthyDatasetMetadata;
        }

        $path = file_exists(storage_path('app/datasets/healthy_dataset_metadata.json'))
            ? storage_path('app/datasets/healthy_dataset_metadata.json')
            : base_path('storage/app/datasets/healthy_dataset_metadata.json');
        $cacheKey = 'oryzatix.dataset_metadata.healthy.' . (file_exists($path) ? filemtime($path) : 'missing');
        $cached = $this->readDatasetMetadataCache($cacheKey);
        if ($cached !== null) {
            return $this->healthyDatasetMetadata = $cached;
        }

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $this->healthyDatasetMetadata = $data['images'] ?? [];
        } else {
            $this->healthyDatasetMetadata = [];
        }

        // Auto-index any newly placed Healthy Leaf images in project/dataset folders
        $healthyFolders = [
            base_path('Healthy Rice Leaf'),
            storage_path('app/dataset/train/healthy'),
            storage_path('app/dataset/val/healthy'),
            storage_path('app/dataset/test/healthy'),
            storage_path('app/dataset/healthy'),
            base_path('healthy'),
        ];

        foreach ($healthyFolders as $folder) {
            if (is_dir($folder)) {
                $files = glob("$folder/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
                foreach ($files as $file) {
                    $fn = basename($file);
                    if (!isset($this->healthyDatasetMetadata[$fn])) {
                        $this->healthyDatasetMetadata[$fn] = [
                            'filename' => $fn,
                            'disease' => 'healthy',
                            'severity' => 'healthy',
                            'affected_percentage' => null,
                            'confidence' => 98.5,
                            'md5' => md5_file($file),
                            'filesize' => filesize($file),
                        ];
                    }
                }
            }
        }

        $this->writeDatasetMetadataCache($cacheKey, $this->healthyDatasetMetadata);
        return $this->healthyDatasetMetadata;
    }

    private function getSheathBlightDatasetMetadata(): array
    {
        if ($this->sheathBlightDatasetMetadata !== null) {
            return $this->sheathBlightDatasetMetadata;
        }

        $path = file_exists(storage_path('app/datasets/sheath_blight_dataset_metadata.json'))
            ? storage_path('app/datasets/sheath_blight_dataset_metadata.json')
            : base_path('storage/app/datasets/sheath_blight_dataset_metadata.json');
        $cacheKey = 'oryzatix.dataset_metadata.sheath_blight.' . (file_exists($path) ? filemtime($path) : 'missing');
        $cached = $this->readDatasetMetadataCache($cacheKey);
        if ($cached !== null) {
            return $this->sheathBlightDatasetMetadata = $cached;
        }

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $this->sheathBlightDatasetMetadata = $data['images'] ?? [];
        } else {
            $this->sheathBlightDatasetMetadata = [];
        }

        // Auto-index any newly placed Sheath Blight images in dataset folders
        $sbFolders = [
            base_path('Sheath Blight'),
            storage_path('app/dataset/train/sheath_blight'),
            storage_path('app/dataset/val/sheath_blight'),
            storage_path('app/dataset/test/sheath_blight'),
            storage_path('app/dataset/sheath_blight'),
        ];

        foreach ($sbFolders as $folder) {
            if (is_dir($folder)) {
                $files = glob("$folder/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
                foreach ($files as $file) {
                    $fn = basename($file);
                    if (!isset($this->sheathBlightDatasetMetadata[$fn])) {
                        $this->sheathBlightDatasetMetadata[$fn] = [
                            'filename' => $fn,
                            'disease' => 'sheath_blight',
                            'severity' => 'unassessed',
                            'affected_percentage' => null,
                            'confidence' => null,
                            'md5' => md5_file($file),
                            'filesize' => filesize($file),
                        ];
                    }
                }
            }
        }

        $this->writeDatasetMetadataCache($cacheKey, $this->sheathBlightDatasetMetadata);
        return $this->sheathBlightDatasetMetadata;
    }

    public function index(): View
    {
        try {
            $userId = auth()->id();
            $query = RiceScan::query();
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $scans = (clone $query)->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();

            $stats = [
                'healthy' => (clone $query)->where('severity', 'healthy')->count(),
                // Older disease severities came from unvalidated color rules.
                'mild' => 0,
                'moderate' => 0,
                'severe' => 0,
                'unassessed' => (clone $query)->where('severity', '!=', 'healthy')->count(),
                'total' => (clone $query)->count(),
            ];
        } catch (Exception $e) {
            $scans = collect();
            $stats = ['healthy' => 0, 'mild' => 0, 'moderate' => 0, 'severe' => 0, 'unassessed' => 0, 'total' => 0];
        }

        return view('rice-detector', compact('scans', 'stats'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  VISUAL / PIXEL ANALYZER — Color-based Disease Detection
    // ──────────────────────────────────────────────────────────────────
    private function analyzeImagePixels(string $imagePath): array
    {
        $result = [
            'green_ratio'       => 0,
            'brown_ratio'       => 0,
            'brown_dark_ratio'  => 0,
            'yellow_ratio'      => 0,
            'orange_ratio'      => 0,
            'white_ratio'       => 0,
            'gray_ratio'        => 0,
            'disease_key'       => 'healthy',
            'disease_score'     => 0.0,
            'confidence'        => 75.0,
            'matched_by'        => 'fallback',
        ];

        try {
            if (!function_exists('imagecreatetruecolor') || !function_exists('imagecolorat') || !function_exists('imagesx')) {
                return $result;
            }
            if (!file_exists($imagePath)) {
                return $result;
            }

            $imageInfo = @getimagesize($imagePath);
            if (!$imageInfo) {
                return $result;
            }

            $mime = $imageInfo['mime'] ?? '';
            $src = null;
            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $src = @imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $src = @imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $src = @imagecreatefromgif($imagePath);
                    break;
            }
            if (!$src) {
                return $result;
            }

            $origW = imagesx($src);
            $origH = imagesy($src);

            $sampleW = 80;
            $sampleH = 80;
            $tmp = imagecreatetruecolor($sampleW, $sampleH);
            imagecopyresampled($tmp, $src, 0, 0, 0, 0, $sampleW, $sampleH, $origW, $origH);
            imagedestroy($src);

            $totalPixels = 0;
            $green = $brown = $darkBrown = $yellow = $orange = $white = $gray = 0;

            for ($y = 0; $y < $sampleH; $y++) {
                for ($x = 0; $x < $sampleW; $x++) {
                    $rgb = imagecolorat($tmp, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    if ($g > 200 && $r < 80 && $b < 120) {
                        continue;
                    }

                    $totalPixels++;

                    $max = max($r, $g, $b);
                    $min = min($r, $g, $b);
                    $brightness = ($r + $g + $b) / 3;

                    if ($r > 180 && $g > 170 && $b > 160 && ($max - $min) < 35) {
                        $white++;
                        continue;
                    }
                    if ($brightness > 80 && $brightness < 170 && ($max - $min) < 28) {
                        $gray++;
                        continue;
                    }
                    if ($r > 150 && $g > 140 && $b < 110 && $r >= $g * 0.85) {
                        $yellow++;
                        continue;
                    }
                    if ($r > 170 && $g > 80 && $g < 160 && $b < 90 && $r > $g + 20) {
                        $orange++;
                        continue;
                    }
                    if ($r > 70 && $r < 200 && $g > 30 && $g < 140 && $b < 100
                        && $r >= $g * 0.9 && $r > $b + 30) {
                        if ($r < 120 && $g < 70) {
                            $darkBrown++;
                        } else {
                            $brown++;
                        }
                        continue;
                    }
                    if ($g > $r + 15 && $g > $b + 15 && $g > 60) {
                        $green++;
                        continue;
                    }
                }
            }
            imagedestroy($tmp);

            if ($totalPixels < 50) {
                $totalPixels = $sampleW * $sampleH;
            }

            $result['green_ratio']      = $green / $totalPixels;
            $result['brown_ratio']      = $brown / $totalPixels;
            $result['brown_dark_ratio'] = $darkBrown / $totalPixels;
            $result['yellow_ratio']     = $yellow / $totalPixels;
            $result['orange_ratio']     = $orange / $totalPixels;
            $result['white_ratio']      = $white / $totalPixels;
            $result['gray_ratio']       = $gray / $totalPixels;
            $result['leaf_ratio']       = ($green + $yellow + $orange + $brown + $darkBrown + $white) / $totalPixels;

            $scores = [];
            foreach ($this->diseaseSignatures as $dKey => $sig) {
                if (!$this->isDatasetSupported($dKey)) {
                    continue;
                }

                $score = 0;

                foreach (['brown_ratio','brown_dark_ratio','yellow_ratio',
                          'orange_ratio','white_ratio','gray_ratio','green_ratio'] as $metric) {
                    if (!isset($sig[$metric])) continue;
                    [$min, $max] = $sig[$metric];
                    $actual = $result[$metric] ?? 0;
                    if ($actual >= $min && $actual <= $max) {
                        $score += ($max - $min + 0.05) > 0.2 ? 2.5 : 3.5;
                    } else if ($actual > $max) {
                        $score += max(0, 1.0 - ($actual - $max) * 3);
                    } else if ($actual < $min) {
                        $score += max(0, 0.8 - ($min - $actual) * 3);
                    }
                }

                if (!empty($sig['spotty']) && ($brown + $darkBrown) > $totalPixels * 0.10) $score += 1.0;
                if (!empty($sig['linear']) && $gray > $totalPixels * 0.05) $score += 0.8;
                if (!empty($sig['green_pale']) && $green > $totalPixels * 0.20 && $green < $totalPixels * 0.55) $score += 0.8;
                if (!empty($sig['green_mottled']) && $yellow > $totalPixels * 0.12) $score += 1.0;

                $scores[$dKey] = $score;
            }

            arsort($scores);
            $topDisease = key($scores);
            $topScore = current($scores);
            $secondScore = next($scores) ?: 0;

            $gap = $topScore - $secondScore;
            $maxPossible = 28.0;
            $confidence = 65 + min(32, ($topScore / $maxPossible) * 55 + $gap * 8);
            $confidence = min(99.0, max(65.0, $confidence));

            $result['disease_key']   = $topDisease;
            $result['disease_score'] = round($topScore, 2);
            $result['confidence']    = round($confidence, 1);
            $result['matched_by']    = 'pixel_color_signature';
            $result['scores_detail'] = array_map(fn($s) => round($s, 2), $scores);

            return $result;
        } catch (Exception $e) {
            return $result;
        }
    }

    /**
     * Determine if a pixel belongs to actual rice leaf tissue (excluding backgrounds, paper, tables, shadows).
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240',
        ]);

        $file = $request->file('image');
        $fullPath = $file->getRealPath();

        $path = null;
        $imageUrl = null;
        $selectedDiseaseKey = null;
        $confidence = null;
        $matchedBy = 'reference_match';
        $matchedEntry = null;
        $isModelPrediction = false;
        $scanPrediction = null;

        if (!$fullPath || !is_file($fullPath)) {
            return $this->unsupportedScanResponse(null, 'model_unavailable', [], 503);
        }

        try {
            if (!$this->hasServerScanAssets()) {
                return $this->unsupportedScanResponse(null, 'model_unavailable', [], 503);
            }

            $scanPrediction = $this->predictScanWithServerModels($fullPath);
            if ($scanPrediction === null) {
                return $this->unsupportedScanResponse(null, 'model_unavailable', [], 503);
            }

            $predictionDetails = [
                'validation' => $scanPrediction['validation'],
                'quality' => $scanPrediction['quality'],
                'rice_leaf_validated' => $scanPrediction['rice_leaf_validated'] === true,
            ];
            if ($scanPrediction['status'] === 'blurry_image') {
                return $this->unsupportedScanResponse(null, 'blurry_image', $predictionDetails);
            }
            if ($scanPrediction['status'] === 'not_rice_leaf') {
                return $this->unsupportedScanResponse(null, 'not_rice_leaf', $predictionDetails);
            }

            if ($scanPrediction['status'] === 'diagnosed') {
                $selectedDiseaseKey = $scanPrediction['diagnosis']['disease_key'];
                $confidence = round((float) $scanPrediction['diagnosis']['confidence'] * 100, 1);
                $matchedBy = 'mobilenet_v2';
                $isModelPrediction = true;
            } else {
                $predictionDetails['diagnosis'] = $scanPrediction['diagnosis'] ?? null;
            }

            // Exact-content references stay supported (including Leaf Blast),
            // but only after the separate rice/quality gate has passed. Their
            // disease confidence is unknown, so it must stay null.
            if (!$selectedDiseaseKey) {
                $allDatasets = [
                    'blb' => $this->getBlbDatasetMetadata(),
                    'brown_spot' => $this->getBrownSpotDatasetMetadata(),
                    'sheath_blight' => $this->getSheathBlightDatasetMetadata(),
                    'healthy' => $this->getHealthyDatasetMetadata(),
                    'blast' => $this->getBlastDatasetMetadata(),
                    'tungro' => $this->getTungroDatasetMetadata(),
                ];
                $uploadMd5 = @md5_file($fullPath);
                $matches = [];
                if (is_string($uploadMd5)) {
                    foreach ($allDatasets as $diseaseKey => $entries) {
                        foreach ($entries as $entry) {
                            $entryMd5 = strtolower((string) ($entry['md5'] ?? ''));
                            if ($entryMd5 !== '' && hash_equals($entryMd5, strtolower($uploadMd5))) {
                                $matches[] = ['key' => $diseaseKey, 'entry' => $entry];
                            }
                        }
                    }
                }

                $matchedDiseaseKeys = array_values(array_unique(array_column($matches, 'key')));
                if (count($matchedDiseaseKeys) === 1) {
                    $selectedDiseaseKey = $matchedDiseaseKeys[0];
                    $matchedEntry = $matches[0]['entry'];
                    $matchedBy = 'reference_match';
                }
            }

            if (!$selectedDiseaseKey) {
                return $this->unsupportedScanResponse(null, 'uncertain_prediction', $predictionDetails);
            }

        } catch (\Throwable $e) {
            Log::error('Rice scan failed unexpectedly.', ['error' => $e->getMessage()]);
            return $this->unsupportedScanResponse(null, 'model_unavailable', [], 500);
        }

        $selectedDiseaseKey = $this->resolveDiseaseKey($selectedDiseaseKey);

        if (!$this->isDatasetSupported($selectedDiseaseKey)) {
            return $this->unsupportedScanResponse($imageUrl, 'not_in_dataset');
        }

        $disease = $this->diseases[$selectedDiseaseKey];
        // The classifier predicts disease class, not lesion area. Do not turn
        // color heuristics or dataset defaults into a severity measurement.
        $severity = $selectedDiseaseKey === 'healthy' ? 'healthy' : 'unassessed';
        $severityStatus = $selectedDiseaseKey === 'healthy' ? 'not_applicable' : 'unassessed';

        // This classifier does not measure lesion area. Suppress disease-specific
        // dosage suggestions until severity is independently assessed.
        $treatments = $selectedDiseaseKey === 'healthy'
            ? ($disease['treatments'] ?? ['chemical' => [], 'organic' => []])
            : ['chemical' => [], 'organic' => []];
        $managementPractices = null;
        $agriculturalStrategies = null;
        $productivityImpact = null;

        if (in_array($selectedDiseaseKey, ['blb', 'sheath_blight', 'tungro', 'blast', 'brown_spot'], true) && isset($disease['severity_levels'][$severity])) {
            $treatments = [
                'chemical' => $disease['severity_levels'][$severity]['chemical'],
                'organic' => $disease['severity_levels'][$severity]['organic'],
                'level_info' => $disease['severity_levels'][$severity],
            ];
            $managementPractices = $disease['severity_levels'][$severity]['management_practices'] ?? null;
            $agriculturalStrategies = $disease['severity_levels'][$severity]['agricultural_strategies'] ?? null;
            $productivityImpact = $disease['severity_levels'][$severity]['productivity_harvest_impact'] ?? null;
        }

        if ($matchedEntry && $selectedDiseaseKey === 'healthy') {
            if (!empty($matchedEntry['recommended_treatments'])) {
                $treatments['chemical'] = $matchedEntry['recommended_treatments']['chemical'] ?? $treatments['chemical'];
                $treatments['organic'] = $matchedEntry['recommended_treatments']['biological'] ?? ($treatments['organic'] ?? []);
            }
            if (!empty($matchedEntry['management_practices'])) {
                $managementPractices = $matchedEntry['management_practices'];
            }
            if (!empty($matchedEntry['agricultural_strategies'])) {
                $agriculturalStrategies = $matchedEntry['agricultural_strategies'];
            }
            if (!empty($matchedEntry['productivity_harvest_impact'])) {
                $productivityImpact = $matchedEntry['productivity_harvest_impact'];
            }
        }

        $scanId = null;
        $saved = false;
        $path = null;

        $dataUrl = $this->createOptimizedDataUrl($fullPath);

        try {
            $user = auth('sanctum')->user() ?: Auth::guard('web')->user() ?: $request->user();
            if ($user) {
                $path = $file->store('scans', 'public');
                $savedImagePath = $dataUrl ?: $path;
                $scan = RiceScan::create([
                    'user_id' => $user->id,
                    'image_path' => $savedImagePath,
                    'disease_name' => $disease['name'],
                    'scientific_name' => $disease['scientific'],
                    'confidence' => $confidence !== null ? round($confidence, 2) : null,
                    'severity' => $severity,
                    'treatment_recommendation' => $treatments,
                ]);
                $scanId = $scan->id;
                $saved = true;
            }
        } catch (\Throwable $e) {
            Log::error('Failed to save scan to database: ' . $e->getMessage());
        }

        $imageUrl = $dataUrl ?: ($path ? url('storage/' . ltrim($path, '/')) : $this->getDiseaseReferenceImageUrl($disease['name']));


        return response()->json([
            'success' => true,
            'recognized' => true,
            'rice_leaf_validated' => true,
            'validation' => $scanPrediction['validation'],
            'quality' => $scanPrediction['quality'],
            'saved' => $saved,
            'scan' => [
                'recognized' => true,
                'disease_key' => $selectedDiseaseKey,
                'id' => $scanId,
                'disease' => $disease['name'],
                'scientific' => $disease['scientific'],
                'analysis_source' => $isModelPrediction ? 'mobilenet_v2' : 'reference_match',
                'confidence' => $confidence !== null ? number_format($confidence, 1) : null,
                'disease_confidence' => $confidence !== null ? number_format($confidence, 1) : null,
                'severity' => $severity,
                'severity_status' => $severityStatus,
                'severity_message' => $severityStatus === 'unassessed'
                    ? 'Affected leaf area was not measured. Severity needs a visual assessment by a trained agriculturist.'
                    : null,
                'affected_percentage' => null,
                'severity_class' => ($severity === 'healthy' ? 'healthy-bg' : 'unassessed-bg'),
                'image_url' => $imageUrl,
                'treatments' => $treatments,
                'management_practices' => $managementPractices,
                'agricultural_strategies' => $agriculturalStrategies,
                'productivity_harvest_impact' => $productivityImpact,
                // Severity guide options are available in the guide flow, but
                // this scan has no validated severity level to select from.
                'all_severity_treatments' => null,
                'date' => now()->format('M j, Y'),
                'time' => now()->format('g:i A'),
            ],
        ]);
    }

    public function history(): JsonResponse
    {
        $formattedScans = [];
        $stats = ['healthy' => 0, 'mild' => 0, 'moderate' => 0, 'severe' => 0, 'unassessed' => 0, 'total' => 0];
        $userId = auth('sanctum')->id() ?: auth()->id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            $query = RiceScan::where('user_id', $userId);

            $scans = (clone $query)->orderBy('created_at', 'desc')
                ->get()
                ->groupBy(function ($scan) {
                    return $scan->created_at->format('Y-m-d') === now()->format('Y-m-d')
                        ? 'Today'
                        : ($scan->created_at->format('Y-m-d') === now()->subDay()->format('Y-m-d')
                            ? 'Yesterday'
                            : $scan->created_at->format('F j, Y'));
                });

            $stats = [
                'healthy' => (clone $query)->where('severity', 'healthy')->count(),
                'mild' => 0,
                'moderate' => 0,
                'severe' => 0,
                'unassessed' => (clone $query)->where('severity', '!=', 'healthy')->count(),
                'total' => (clone $query)->count(),
            ];

            foreach ($scans as $date => $dateScans) {
                $formattedScans[$date] = $dateScans->map(function ($scan) {
                    $diseaseLower = strtolower($scan->disease_name);
                    $isHealthy = $scan->severity === 'healthy' || str_contains($diseaseLower, 'healthy');
                    $displaySeverity = $isHealthy ? 'healthy' : 'unassessed';
                    $severityClass = $isHealthy ? 'healthy-bg' : 'unassessed-bg';

                    $scanImageUrl = $this->resolveScanImageUrl($scan->image_path, $scan->disease_name);

                    return [
                        'id' => $scan->id,
                        'disease' => $scan->disease_name,
                        'scientific' => $scan->scientific_name,
                        'confidence' => $scan->confidence !== null ? number_format((float) $scan->confidence, 1) : null,
                        'time' => $scan->created_at->format('g:i A'),
                        'date' => $scan->created_at->format('M j, Y'),
                        'created_at_raw' => $scan->created_at->toISOString(),
                        'severity' => $displaySeverity,
                        'severity_status' => $isHealthy ? 'not_applicable' : 'unassessed',
                        'severity_label' => $isHealthy ? 'Healthy' : 'Needs assessment',
                        'affected_percentage' => null,
                        'severity_class' => $severityClass,
                        'image_url' => $scanImageUrl,
                        // Legacy records may contain dosage suggestions selected
                        // from guessed severity. Do not replay those as measured advice.
                        'treatments' => $isHealthy ? $scan->treatment_recommendation : null,
                    ];
                });
            }
        } catch (\Throwable $e) {
            Log::error('Failed to load scan history: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load scan history.'], 500);
        }

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'scans' => $formattedScans,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $userId = auth('sanctum')->id() ?: auth()->id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            $scan = RiceScan::where('id', $id)
                ->where('user_id', $userId)
                ->first();
            if (!$scan) {
                return response()->json(['success' => false, 'message' => 'Scan not found.'], 404);
            }

            $imagePath = $scan->image_path;
            $scan->delete();
            if ($imagePath && !str_starts_with($imagePath, 'data:') && !str_starts_with($imagePath, 'http://') && !str_starts_with($imagePath, 'https://')) {
                try {
                    if (!Storage::disk('public')->delete($imagePath)) {
                        Log::warning('Scan deleted but stored image could not be removed: ' . $imagePath);
                    }
                } catch (\Throwable $cleanupError) {
                    Log::warning('Scan deleted but image cleanup failed: ' . $cleanupError->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to delete scan: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete scan.'], 500);
        }

        return response()->json(['success' => true]);
    }

    private function resolveDiseaseKey(string $key): string
    {
        $aliases = [
            'rice_hispa' => 'hispa',
            'downy' => 'downy_mildew',
            'downy_mildew' => 'downy_mildew',
            'leaf_smut' => 'leaf_smut',
        ];

        $key = $aliases[$key] ?? $key;

        if ($this->isDatasetSupported($key)) {
            return $key;
        }

        return 'unsupported';
    }

    private function diseaseKeyFromName(?string $name): ?string
    {
        $normalized = strtolower(trim((string)$name));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->diseases as $key => $disease) {
            if (strtolower(trim((string)($disease['name'] ?? ''))) === $normalized) {
                return $key;
            }
        }

        foreach ([
            'blast' => ['leaf blast', 'rice blast'],
            'blb' => ['bacterial leaf blight', 'bacterial blight'],
            'brown_spot' => ['brown spot'],
            'sheath_blight' => ['sheath blight'],
            'tungro' => ['tungro'],
            'healthy' => ['healthy'],
        ] as $key => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalized, $alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public function treatmentGuides(): JsonResponse
    {
        $dbDiseases = \App\Models\Disease::where('status', 'active')->get()->keyBy('code');
        $result = [];

        foreach ($this->diseases as $key => $diseaseData) {
            $dbItem = $dbDiseases->get($key);
            $result[] = [
                'key' => $key,
                'name' => $dbItem?->name ?: $diseaseData['name'],
                'scientific_name' => $dbItem?->scientific_name ?: $diseaseData['scientific'],
                'description' => $dbItem?->description ?: ($diseaseData['name'] . ' affecting rice cultivation.'),
                'symptoms' => $dbItem?->symptoms ?: null,
                'causes' => $dbItem?->causes ?: null,
                'prevention' => $dbItem?->prevention ?: null,
                'recommended_treatment' => $dbItem?->recommended_treatment ?: null,
                'image_url' => $this->resolveScanImageUrl($dbItem?->image_path, $diseaseData['name']),
                'severity_class' => $diseaseData['severity_class'] ?? 'blast-bg',
                'severity_levels' => $diseaseData['severity_levels'] ?? null,
                'treatments' => $diseaseData['treatments'] ?? [
                    'chemical' => $dbItem?->chemical_treatments ?: [],
                    'organic' => $dbItem?->organic_treatments ?: [],
                ],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function dashboardStats(Request $request): JsonResponse
    {
        $userId = auth('sanctum')->id() ?: auth()->id();
        $query = RiceScan::query();
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $totalScans = (clone $query)->count();
        $healthyCount = (clone $query)->where('severity', 'healthy')->count();
        // No disease-area model has been validated against expert labels.
        $mildCount = 0;
        $moderateCount = 0;
        $severeCount = 0;
        $unassessedCount = (clone $query)->where('severity', '!=', 'healthy')->count();

        // The public stats endpoint may show aggregate totals, but never individual
        // scan records or uploaded image URLs.
        $recentScans = $userId ? (clone $query)->latest()->take(5)->get()->map(function ($scan) {
            $imageUrl = $this->resolveScanImageUrl($scan->image_path, $scan->disease_name);
            return [
                'id' => $scan->id,
                'disease' => $scan->disease_name,
                        'disease_key' => $this->diseaseKeyFromName($scan->disease_name),
                'scientific' => $scan->scientific_name,
                'confidence' => $scan->confidence !== null ? number_format((float) $scan->confidence, 1) : null,
                'severity' => $scan->severity === 'healthy' ? 'healthy' : 'unassessed',
                'severity_status' => $scan->severity === 'healthy' ? 'not_applicable' : 'unassessed',
                'time' => $scan->created_at->format('g:i A'),
                'date' => $scan->created_at->format('M j, Y'),
                'image_url' => $imageUrl,
            ];
        }) : collect();

        return response()->json([
            'success' => true,
            'stats' => [
                'total_scans' => $totalScans,
                'healthy_count' => $healthyCount,
                'mild_count' => $mildCount,
                'moderate_count' => $moderateCount,
                'severe_count' => $severeCount,
                'unassessed_count' => $unassessedCount,
                'health_index' => $totalScans > 0 ? round(($healthyCount / $totalScans) * 100, 1) : 0.0,
            ],
            'recent_scans' => $recentScans,
        ]);
    }

    /**
     * Generate an optimized JPEG base64 Data URL (max 640px dimension, ~40-65KB).
     * This self-contained data URL ensures scanned leaf images are permanently preserved in
     * the database and load seamlessly across mobile, desktop, Vercel, and Render.
     */
    private function createOptimizedDataUrl(string $filePath, int $maxDimension = 640, int $quality = 75): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $imageInfo = @getimagesize($filePath);
        if (!$imageInfo) {
            return null;
        }

        [$origWidth, $origHeight, $imageType] = $imageInfo;

        // Some deployment images may have GD enabled without every codec.
        // Never call an unavailable decoder: retain a small original image as
        // a data URL, or let the caller use its stored-file URL for larger files.
        if (!function_exists('imagecreatetruecolor')
            || !function_exists('imagecopyresampled')
            || !function_exists('imagejpeg')) {
            return $this->originalImageDataUrl($filePath, $imageInfo);
        }

        $src = match ($imageType) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($filePath) : null,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($filePath) : null,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };

        if (!$src) {
            return $this->originalImageDataUrl($filePath, $imageInfo);
        }

        $newWidth = $origWidth;
        $newHeight = $origHeight;

        if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
            if ($origWidth > $origHeight) {
                $newWidth = $maxDimension;
                $newHeight = (int) round(($origHeight / $origWidth) * $maxDimension);
            } else {
                $newHeight = $maxDimension;
                $newWidth = (int) round(($origWidth / $origHeight) * $maxDimension);
            }
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);

        if ($imageType === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        ob_start();
        imagejpeg($dst, null, $quality);
        $data = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if (!$data) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode($data);
    }

    /** Keep small original uploads viewable when GD or an image codec is unavailable. */
    private function originalImageDataUrl(string $filePath, array $imageInfo): ?string
    {
        $fileSize = @filesize($filePath);
        if ($fileSize === false || $fileSize > 2 * 1024 * 1024) {
            return null;
        }

        $contents = @file_get_contents($filePath);
        if (!is_string($contents)) {
            return null;
        }

        $mime = $imageInfo['mime'] ?? 'application/octet-stream';
        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    /**
     * Resolve a robust, non-broken URL for any scan image.
     * Handles Data URLs, local storage paths, and falls back to disease reference photos.
     */
    public function resolveScanImageUrl(?string $imagePath, ?string $diseaseName = null): string
    {
        if (!empty($imagePath)) {
            if (str_starts_with($imagePath, 'data:')) {
                return $imagePath;
            }

            if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
                // If it points to localhost/storage from a non-localhost client, adapt to current origin
                if (str_contains($imagePath, 'localhost') && !in_array(request()->getHost(), ['localhost', '127.0.0.1'])) {
                    $parsed = parse_url($imagePath);
                    $pathPart = $parsed['path'] ?? '';
                    if ($pathPart) {
                        return url($pathPart);
                    }
                }
                return $imagePath;
            }

            // If file exists on public disk, serve it via current request URL
            $cleanPath = ltrim($imagePath, '/');
            if (Storage::disk('public')->exists($cleanPath)) {
                return url('storage/' . $cleanPath);
            }
        }

        // Fallback to official disease sample reference photo
        return $this->getDiseaseReferenceImageUrl($diseaseName);
    }

    public function getDiseaseReferenceImageUrl(?string $diseaseName): string
    {
        $name = strtolower((string) $diseaseName);
        $filename = 'healthy.jpg';

        if (str_contains($name, 'blight') || str_contains($name, 'sheath')) {
            $filename = 'sheath_blight.jpg';
        } elseif (str_contains($name, 'blb') || str_contains($name, 'bacterial')) {
            $filename = 'blb.webp';
        } elseif (str_contains($name, 'brown')) {
            $filename = 'brown_spot.webp';
        } elseif (str_contains($name, 'tungro')) {
            $filename = 'tungro.jpg';
        } elseif (str_contains($name, 'blast')) {
            $filename = 'blast.webp';
        } elseif (str_contains($name, 'healthy')) {
            $filename = 'healthy.jpg';
        }

        return asset('images/diseases/' . $filename);
    }
}


