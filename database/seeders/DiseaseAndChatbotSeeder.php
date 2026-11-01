<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Disease;
use App\Models\ChatbotKnowledge;

class DiseaseAndChatbotSeeder extends Seeder
{
    public function run(): void
    {
        $diseases = [
            [
                'name' => 'Bacterial Leaf Blight',
                'code' => 'blb',
                'scientific_name' => 'Xanthomonas oryzae pv. oryzae',
                'image_path' => 'images/blb.jpg',
                'description' => 'A deadly bacterial disease causing severe wilting (kresek) in young plants and yellow-white wavy lesions along leaf margins in mature rice crops.',
                'symptoms' => 'Water-soaked to yellowish stripes with wavy margins on leaf blades; milky or amber bacterial ooze droplets in early mornings; leaves turn grayish-white and dry up.',
                'causes' => 'Xanthomonas oryzae bacteria entering through leaf wounds or hydathodes; aggravated by high nitrogen fertilizer, continuous flooding, rainstorms, and typhoons.',
                'prevention' => 'Use certified resistant rice varieties (e.g. NSIC Rc lines); practice balanced fertilization (avoid excess Urea); maintain clean paddies and avoid deep flooding during tillering.',
                'recommended_treatment' => 'Apply Copper Hydroxide or Copper Oxychloride (2.0 g/L) at early sign. Drain paddy water for 2-3 days. For systemic outbreaks, apply emergency Streptomycin + Oxytetracycline.',
                'chemical_treatments' => [
                    ['title' => 'Copper Hydroxide 77% WP (Kocide / Vitigran)', 'rate' => '1.5 - 2.5 g / Liter', 'timing' => 'Apply at first sign of lesions on leaf edges.'],
                    ['title' => 'Streptomycin + Oxytetracycline (Plantomycin)', 'rate' => '1.0 - 1.5 g / Liter', 'timing' => 'Emergency systemic bactericide for active outbreaks.']
                ],
                'organic_treatments' => [
                    ['title' => 'Garlic-Chili Fermented Plant Extract (FPJ)', 'rate' => '50 - 100 ml / 16L knapsack', 'timing' => 'Natural antimicrobial spray twice weekly.'],
                    ['title' => 'Field Water Drainage (Dry-Wet Cycling)', 'rate' => 'Drain for 48-72 hours', 'timing' => 'Stop bacterial ooze dispersal by draining standing water.']
                ],
                'status' => 'active',
            ],
            [
                'name' => 'Rice Leaf Blast',
                'code' => 'blast',
                'scientific_name' => 'Magnaporthe oryzae (Pyricularia oryzae)',
                'image_path' => 'images/blast.jpg',
                'description' => 'A destructive fungal pathogen attacking leaves, nodes, and panicles, causing diamond or spindle-shaped necrotic lesions.',
                'symptoms' => 'Spindle-shaped or diamond lesions with gray or whitish centers and reddish-brown borders; lesions coalesce killing whole leaves.',
                'causes' => 'Airborne fungal spores thriving in high relative humidity (>90%), cool night temperatures (18-24°C), and excessive nitrogen fertilization.',
                'prevention' => 'Plant certified resistant seeds; practice split application of nitrogen; avoid dense planting to improve canopy airflow; destroy infected straw after harvest.',
                'recommended_treatment' => 'Apply systemic fungicides like Tricyclazole 75% WP or Azoxystrobin + Difenoconazole at first lesion detection.',
                'chemical_treatments' => [
                    ['title' => 'Tricyclazole 75% WP (Beam / Blast-Off)', 'rate' => '0.6 - 1.0 g / Liter', 'timing' => 'Prophylactic or early therapeutic application.'],
                    ['title' => 'Kasugamycin 2% (Kasumin 2L)', 'rate' => '1.5 - 2.0 ml / Liter', 'timing' => 'Bio-antibiotic fungicide applied during early vegetative stage.']
                ],
                'organic_treatments' => [
                    ['title' => 'Trichoderma harzianum Bio-inoculant', 'rate' => '200 g / 16L sprayer', 'timing' => 'Apply to soil and foliar canopy to outcompete fungal pathogens.'],
                    ['title' => 'Wood Vinegar (Pyroligneous Acid)', 'rate' => '1:500 dilution', 'timing' => 'Foliar spray weekly during humid weather.']
                ],
                'status' => 'active',
            ],
            [
                'name' => 'Brown Spot',
                'code' => 'brown_spot',
                'scientific_name' => 'Bipolaris oryzae (Cochliobolus miyabeanus)',
                'image_path' => 'images/brown_spot.jpg',
                'description' => 'A chronic fungal infection strongly linked to poor soil fertility, zinc/potassium deficiency, and nutrient-stressed crops.',
                'symptoms' => 'Small circular to oval brown spots with distinct yellow halo; large lesions have dark brown margins with lighter center.',
                'causes' => 'Fungal spores flourishing in nutrient-depleted, drought-stressed, or sandy soils lacking potassium (K), silicon (Si), and zinc (Zn).',
                'prevention' => 'Improve soil fertility by applying complete NPK fertilizer and organic compost; apply Zinc Sulfate (ZnSO4) and Muriate of Potash (MOP); avoid soil drying during vegetative growth.',
                'recommended_treatment' => 'Apply foliar potassium/zinc fertilizer and spray protective fungicides like Propiconazole + Difenoconazole.',
                'chemical_treatments' => [
                    ['title' => 'Difenoconazole + Propiconazole (Tilt / Amistar)', 'rate' => '1.0 ml / Liter', 'timing' => 'Apply when spots exceed 10% of leaf area.'],
                    ['title' => 'Mancozeb 80% WP (Dithane M-45)', 'rate' => '2.5 - 3.0 g / Liter', 'timing' => 'Broad-spectrum contact protective spray.']
                ],
                'organic_treatments' => [
                    ['title' => 'Muriate of Potash (0-0-60) + Rice Hull Ash (RHA)', 'rate' => '30 - 40 kg/ha soil amendment', 'timing' => 'Boosts leaf silica and potassium to strengthen cell walls.'],
                    ['title' => 'Compost Tea & Vermitea Extract', 'rate' => '10% foliar spray', 'timing' => 'Supplies beneficial microbes and micronutrients.']
                ],
                'status' => 'active',
            ],
            [
                'name' => 'Sheath Blight',
                'code' => 'sheath_blight',
                'scientific_name' => 'Rhizoctonia solani (Thanatephorus cucumeris)',
                'image_path' => 'images/diseases/sheath_blight.jpg',
                'description' => 'A fungal disease caused by Rhizoctonia solani that commonly starts on rice leaf sheaths near the waterline and may spread to leaf blades.',
                'symptoms' => 'Small greenish-gray oval lesions on leaf sheaths, often near the waterline, that can enlarge into irregular lesions with grayish-white centers and dark brown borders.',
                'causes' => 'Caused by Rhizoctonia solani. The fungus forms small sclerotia on or near lesions; dense canopies and humid conditions can favor disease development.',
                'prevention' => 'Scout lower leaf sheaths, avoid excessively dense planting and excess nitrogen, and follow balanced crop nutrition and field-sanitation advice from local agricultural staff.',
                'recommended_treatment' => 'Use integrated field management and monitor whether lesions are spreading. If fungicide treatment is being considered, ask the local agriculture office for a product registered for rice sheath blight and follow its label exactly.',
                'chemical_treatments' => [
                    ['title' => 'Locally registered fungicide for sheath blight', 'rate' => 'Follow the product label', 'timing' => 'Use only a product labeled for rice sheath blight in your area; confirm product, timing, and rate with local agricultural staff.'],
                ],
                'organic_treatments' => [
                    ['title' => 'Canopy and nitrogen management', 'rate' => 'Follow local crop recommendations', 'timing' => 'Avoid excessive nitrogen and overly dense crop canopies; scout the lower leaf sheaths regularly.'],
                    ['title' => 'Crop-residue management', 'rate' => 'After harvest', 'timing' => 'Manage infected crop residues according to local extension advice.'],
                ],
                'status' => 'active',
            ],
            [
                'name' => 'Rice Tungro Disease',
                'code' => 'tungro',
                'scientific_name' => 'Rice Tungro Bacilliform (RTBV) & Spherical (RTSV) Viruses',
                'image_path' => 'images/tungro.jpg',
                'description' => 'A viral disease transmitted by the Green Leafhopper (Nephotettix virescens) causing severe plant stunting and bright yellow-orange discoloration.',
                'symptoms' => 'Yellow or orange-yellow discoloration starting from leaf tips; severe stunting of tillers; delayed flowering and sterile panicles.',
                'causes' => 'Dual virus infection (RTBV & RTSV) transmitted within minutes by feeding Green Leafhoppers (GLH).',
                'prevention' => 'Synchronous planting across neighboring fields; observe a 1-month fallow period; use light traps to monitor leafhopper population; plant Tungro-tolerant varieties.',
                'recommended_treatment' => 'No direct chemical cure for the virus. Immediately rogue (uproot & bury) infected plants. Spray vector-control insecticides against Green Leafhoppers.',
                'chemical_treatments' => [
                    ['title' => 'Dinotefuran 20% SG (Venom / Starkle)', 'rate' => '0.5 - 1.0 g / Liter', 'timing' => 'Systemic insecticide targeting green leafhopper vectors.'],
                    ['title' => 'Pymetrozine 50% WDG (Chess)', 'rate' => '0.4 - 0.6 g / Liter', 'timing' => 'Immediate feeding-blocker for hopper vectors.']
                ],
                'organic_treatments' => [
                    ['title' => 'Rogueing & Destruction of Infected Clumps', 'rate' => '100% removal of infected plants', 'timing' => 'Uproot immediately, seal in plastic bag, and bury to prevent viral spread.'],
                    ['title' => 'Neem Oil + Soap Emulsion', 'rate' => '5 ml / Liter water', 'timing' => 'Natural insect repellent against leafhoppers.']
                ],
                'status' => 'active',
            ],
            [
                'name' => 'Healthy Rice Leaf',
                'code' => 'healthy',
                'scientific_name' => 'Oryza sativa L.',
                'image_path' => 'images/healthy.jpg',
                'description' => 'Vibrant green, vigorous rice leaves free of pathogenic lesions, necrotic spotting, or vector feeding marks.',
                'symptoms' => 'Uniform green coloration, firm upright leaf blade, no discoloration, spots, or wilting.',
                'causes' => 'Optimal agronomic practices, balanced soil nutrition, proper irrigation, and pest management.',
                'prevention' => 'Maintain regular scouting, optimal water levels (3-5 cm), and apply balanced fertilization according to crop growth stages.',
                'recommended_treatment' => 'Continue standard care: apply fertilizer split (basal, tillering, panicle initiation), maintain alternate wetting and drying (AWD) irrigation.',
                'chemical_treatments' => [
                    ['title' => 'Balanced NPK Fertilizer (14-14-14 / 16-20-0)', 'rate' => 'Standard DA-PhilRice rate', 'timing' => 'Apply during tillering and panicle development.']
                ],
                'organic_treatments' => [
                    ['title' => 'Fermented Fruit Juice (FFJ) / Vermicompost', 'rate' => '2 tbsp / Liter water', 'timing' => 'Apply foliar nutrients during reproductive phase.']
                ],
                'status' => 'active',
            ],
        ];

        foreach ($diseases as $d) {
            Disease::updateOrCreate(['code' => $d['code']], $d);
        }

        $faqs = [
            [
                'question' => 'Ano ang mabisang gamot sa Rice Leaf Blast?',
                'answer' => 'Para sa Rice Leaf Blast, mabisang gamot ang Tricyclazole 75% WP (tulad ng Beam o Blast-Off) sa sukat na 0.6-1.0 g bawat litro ng tubig, o kaya Kasugamycin (Kasumin 2L). Iwasan ang labis na Urea at panatilihing may tubig ang pilapil.',
                'category' => 'Treatment',
                'language' => 'tagalog',
                'status' => 'active',
            ],
            [
                'question' => 'Paano maiiwasan ang Bacterial Leaf Blight (BLB)?',
                'answer' => 'Iwasan ang sobrang pataba na Urea (Nitrogen) lalo na sa tag-ulan. Mag-spray ng Copper Hydroxide (Kocide) o Copper Oxychloride kapag may nakitang mga unang guhit sa gilid ng dahon, at patuyuin (i-drain) ang tubig sa palayan ng 2-3 araw.',
                'category' => 'Prevention',
                'language' => 'tagalog',
                'status' => 'active',
            ],
            [
                'question' => 'Ano ang gagawin kapag may Tungro ang palay?',
                'answer' => 'Ang Rice Tungro ay dulot ng virus na dinadala ng Green Leafhopper. Walang direktang kemikal sa virus kaya kailangan bunutin (rogueing) at ilibing agad ang mga naninilaw/nag-o-orange na palay, at mag-spray ng Dinotefuran o Pymetrozine para patayin ang mga insektong nanginginain.',
                'category' => 'Treatment',
                'language' => 'tagalog',
                'status' => 'active',
            ],
            [
                'question' => 'Bakit may mga brown spots ang dahon ng palay ko?',
                'answer' => 'Ang Brown Spot ay kadalasang palatandaan ng kakulangan sa Potassium (K), Silicon (Si), o Zinc sa lupa, o kaya ay dahil sa tagtuyot. Maglagay ng Muriate of Potash (0-0-60) at Zinc Sulfate upang mapalakas ang resistensya ng palay.',
                'category' => 'Disease',
                'language' => 'tagalog',
                'status' => 'active',
            ],
            [
                'question' => 'What is the recommended spray dosage per knapsack sprayer?',
                'answer' => 'For a standard 16-Liter knapsack sprayer, typical fungicide dosage is 10-15 grams for WP powders (e.g. Tricyclazole) or 15-25 ml for liquid concentrates. Always follow specific product label instructions and use clean water.',
                'category' => 'Dosage',
                'language' => 'english',
                'status' => 'active',
            ],
            [
                'question' => 'How often should I scan my rice crops?',
                'answer' => 'It is recommended to scan and inspect your rice fields at least once a week during the vegetative stage, and twice a week during rainy or humid weather conditions when fungal and bacterial spores spread quickly.',
                'category' => 'General',
                'language' => 'english',
                'status' => 'active',
            ],
        ];

        foreach ($faqs as $f) {
            ChatbotKnowledge::updateOrCreate(['question' => $f['question']], $f);
        }
    }
}
