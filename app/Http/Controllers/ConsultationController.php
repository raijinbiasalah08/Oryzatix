<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatbotKnowledge;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class ConsultationController extends Controller
{
    private $diseaseResponses = [
        'blast' => [
            'tagalog' => "### 🌾 Gabay sa Rice Leaf Blast / Neck Blast (*Magnaporthe oryzae*)\n\n" .
                "**Mga Sintomas:** Hugis-brilyante (diamond / spindle-shaped) na mga sugat na may abong gitna at mapulang kayumangging gilid sa dahon o leeg ng uhay.\n\n" .
                "**1. Kemikal na Lunas (Fungicides):**\n" .
                "• **Tricyclazole 75% WP** (hal. Beam, Blast-Off): **0.6 – 0.8 g / Litro** ng tubig (10–12 g bawat 16L knapsack sprayer).\n" .
                "• **Isoprothiolane 40% EC** (hal. Fuji-One): **1.5 – 2.0 ml / Litro** ng tubig (25–30 ml bawat 16L sprayer).\n" .
                "• **Azoxystrobin + Difenoconazole**: **1.0 ml / Litro** ng tubig.\n\n" .
                "**2. Kultural at Organikong Pamamahala:**\n" .
                "• **Itigil ang sobrang Urea (Nitrogen):** Ang labis na nitrogen ay nagpapadali sa paglambot ng dahon kaya mabilis kapitan ng amag.\n" .
                "• **Mag-apply ng Potash (0-0-60):** 30–40 kg/ha upang patibayin ang cell walls ng dahon.\n" .
                "• **Panatilihing may mababaw na tubig (3–5 cm):** Huwag hayaang matuyuan ang bukid sa panahon ng pagsusuwi (tillering).\n" .
                "• **Resistant Varieties:** Magtanim ng mga barayti tulad ng **PSB Rc18**, **NSIC Rc222**, o **NSIC Rc160**.",
            'english' => "### 🌾 Rice Leaf Blast / Neck Blast Guide (*Magnaporthe oryzae*)\n\n" .
                "**Symptoms:** Spindle-shaped/diamond lesions with gray centers and reddish-brown margins on leaves, leaf collars, and panicle necks.\n\n" .
                "**1. Chemical Treatments (Fungicides):**\n" .
                "• **Tricyclazole 75% WP**: **0.6 – 0.8 g / Liter** of water (10–12 g per 16L knapsack sprayer).\n" .
                "• **Isoprothiolane 40% EC**: **1.5 – 2.0 ml / Liter** of water (25–30 ml per 16L sprayer).\n" .
                "• **Azoxystrobin + Difenoconazole**: **1.0 ml / Liter** of water.\n\n" .
                "**2. Cultural & Agronomic Management:**\n" .
                "• **Suspend Nitrogen Topdressing:** Avoid excess urea which creates lush, succulent leaf tissue.\n" .
                "• **Apply Muriate of Potash (0-0-60 @ 30–40 kg/ha):** Strengthens silica and epidermal cell walls.\n" .
                "• **Maintain Shallow Water (3–5 cm):** Prevent drought stress during tillering.\n" .
                "• **Resistant Varieties:** Plant certified varieties such as **PSB Rc18**, **NSIC Rc222**, or **NSIC Rc160**.",
        ],
        'blight' => [
            'tagalog' => "### 🌾 Gabay sa Bacterial Leaf Blight / BLB (*Xanthomonas oryzae pv. oryzae*)\n\n" .
                "**Mga Sintomas:** Wavy o kulubot na naninilaw hanggang nagiging puti/kulay-dayami na mga guhit na nagsisimula sa dulo o gilid ng dahon patungong ibaba.\n\n" .
                "**1. Lunas Batay sa Severity Level:**\n" .
                "• **Mild (≤25%):** Mag-spray ng **Copper Hydroxide 77% WP** (**2.0 – 2.5 g / L**) o **Kasugamycin 2% SL** (**2.0 ml / L**). Agad itigil ang pag-abono ng Urea.\n" .
                "• **Moderate (26% – 60%):** Mag-apply ng **Streptomycin Sulfate + Oxytetracycline** (**1.5 – 2.0 g / L**) o **Zinc Thiazole 20% SC** (**1.5 – 2.0 ml / L**). I-drain o patuyuin ang palayan ng 2–3 araw.\n" .
                "• **Severe (>60%):** Therapeutic **Streptomycin** (**2.0 – 2.5 g / L**) o tank-mix ng Zinc Thiazole at Copper Hydroxide.\n\n" .
                "**2. Pag-iwas at Pangmatagalang Solusyon:**\n" .
                "• **I-drain ang bukid:** Ang nakatenggang tubig-baha ay nagpapabilis ng pagkalat ng bakterya.\n" .
                "• **Resistant Varieties:** Gumamit ng binhi na may panlaban sa BLB tulad ng **PSB Rc82**, **NSIC Rc152**, o **NSIC Rc222**.",
            'english' => "### 🌾 Bacterial Leaf Blight (BLB) Guide (*Xanthomonas oryzae pv. oryzae*)\n\n" .
                "**Symptoms:** Water-soaked to yellowish stripes with wavy margins starting from leaf tips and margins, later turning grayish-white like bleached straw.\n\n" .
                "**1. Severity-Calibrated Treatments:**\n" .
                "• **Mild (≤25%):** Apply **Copper Hydroxide 77% WP** (**2.0–2.5 g/L**) or **Kasugamycin 2% SL** (**2.0 ml/L**). Immediately halt nitrogen topdressing.\n" .
                "• **Moderate (26%–60%):** Spray **Streptomycin Sulfate + Oxytetracycline** (**1.5–2.0 g/L**) or **Zinc Thiazole 20% SC** (**1.5–2.0 ml/L**). Drain field for 2–3 days.\n" .
                "• **Severe (>60%):** Therapeutic **Streptomycin** (**2.0–2.5 g/L**) combined with strict field drainage and sanitation.\n\n" .
                "**2. Prevention & Agronomy:**\n" .
                "• **Water Drainage:** Prevent stagnant floodwater from spreading bacterial ooze.\n" .
                "• **Plant Resistant Cultivars:** Use **PSB Rc82**, **NSIC Rc152**, or **NSIC Rc222**.",
        ],
        'brown' => [
            'tagalog' => "### 🌾 Gabay sa Brown Spot (*Bipolaris oryzae*)\n\n" .
                "**Mga Sintomas:** Pabilog o hugis-itlog na dark brown spots na may dilaw na halo sa paligid. Pangunahing sanhi ng mahinang lupa at kakulangan sa sustansya.\n\n" .
                "**1. Gamot at Pagsugpo:**\n" .
                "• **Mancozeb 80% WP**: **2.0 – 2.5 g / L** ng tubig (30–40 g bawat 16L knapsack sprayer).\n" .
                "• **Propiconazole 25% EC**: **0.75 – 1.0 ml / L** ng tubig (15 ml bawat 16L sprayer).\n" .
                "• **Azoxystrobin + Difenoconazole**: **1.0 ml / L** ng tubig.\n\n" .
                "**2. Pagpapabuti ng Sustansya sa Lupa:**\n" .
                "• **Kakulangan sa Potassium:** Mag-abono ng **Muriate of Potash (0-0-60 @ 30–40 kg/ha)**.\n" .
                "• **Kakulangan sa Zinc:** Maglagay ng **Zinc Sulfate (25 kg/ha)** bago magtanim o sa basal stage.\n" .
                "• **Organikong Pataba:** Maglagay ng 2–3 tonelada bawat ektarya ng compost o pinunong dayami upang mapataas ang Silica (Si).",
            'english' => "### 🌾 Brown Spot Disease Guide (*Bipolaris oryzae*)\n\n" .
                "**Symptoms:** Small, circular to oval dark-brown lesions with yellowish halo. Primary indicator of poor soil fertility and potassium/zinc deficiency.\n\n" .
                "**1. Fungicide Treatments:**\n" .
                "• **Mancozeb 80% WP**: **2.0 – 2.5 g / L** (30–40 g per 16L sprayer).\n" .
                "• **Propiconazole 25% EC**: **0.75 – 1.0 ml / L** (15 ml per 16L sprayer).\n" .
                "• **Azoxystrobin + Difenoconazole**: **1.0 ml / L**.\n\n" .
                "**2. Soil Fertility Rectification:**\n" .
                "• **Potassium Supplementation:** Apply **Muriate of Potash (0-0-60 @ 30–40 kg/ha)** split into basal and panicle initiation.\n" .
                "• **Zinc Supplementation:** Apply **Zinc Sulfate (25 kg/ha)**.\n" .
                "• **Soil Conditioning:** Incorporate 2–3 tons/ha organic compost or rice hull ash for silica boost.",
        ],
        'tungro' => [
            'tagalog' => "### 🌾 Gabay sa Rice Tungro Disease (RTV)\n\n" .
                "**Mga Sintomas:** Pagkabansot ng palay, pagkaunti ng suwi, at paninilaw hanggang pagka-orange ng mga dahon mula sa dulo. Dulot ng virus na hatid ng **Green Leafhopper (GLH)**.\n\n" .
                "**1. Pagsugpo sa Insekto (GLH Vector Control):**\n" .
                "• **Dinotefuran 20% SG**: **0.5 – 1.0 g / L** ng tubig (10–15 g bawat 16L sprayer).\n" .
                "• **Imidacloprid 17.8% SL**: **0.5 – 0.75 ml / L** ng tubig.\n" .
                "• **Buprofezin 25% SC**: **1.5 – 2.0 ml / L** ng tubig (pumipigil sa paglaki ng mga batang insekto).\n\n" .
                "**2. Kultural at Paglilinis (Rogueing):**\n" .
                "• **Bunutin at Ibaon (Rogueing):** Agad bunutin at ibaon sa putik ang mga palay na apektado ng Tungro upang hindi na maging imbakan ng virus.\n" .
                "• **Sabayang Pagtatanim (Synchronous Planting):** Magtanim sa loob ng 2 linggo kasabay ng mga katabing bukid.\n" .
                "• **Resistant Varieties:** Gumamit ng **NSIC Rc160**, **PSB Rc10**, o **NSIC Rc222**.",
            'english' => "### 🌾 Rice Tungro Virus (RTV) Guide\n\n" .
                "**Symptoms:** Severe stunting, reduced tillering, and yellow-to-orange leaf discoloration starting from the tips. Transmitted by the **Green Leafhopper (GLH)** (*Nephotettix virescens*).\n\n" .
                "**1. Vector Control (Insecticides):**\n" .
                "• **Dinotefuran 20% SG**: **0.5 – 1.0 g / L** (10–15 g per 16L knapsack sprayer).\n" .
                "• **Imidacloprid 17.8% SL**: **0.5 – 0.75 ml / L**.\n" .
                "• **Buprofezin 25% SC**: **1.5 – 2.0 ml / L** (insect growth regulator).\n\n" .
                "**2. Cultural & Sanitation Practices:**\n" .
                "• **Systematic Rogueing:** Immediately uproot and bury infected plants into deep mud.\n" .
                "• **Synchronous Planting:** Plant within 2 weeks of neighboring fields to break vector cycles.\n" .
                "• **Resistant Varieties:** Plant **NSIC Rc160**, **PSB Rc10**, or **NSIC Rc222**.",
        ],
        'sheath' => [
            'tagalog' => "### 🌾 Gabay sa Sheath Blight (*Rhizoctonia solani*)\n\n" .
                "**Mga Sintomas:** Hugis-itlog o parang 'snake-skin' na mga batik na may dark brown border sa may bandang ibaba ng puno at saha malapit sa tubig.\n\n" .
                "**1. Mabisang Gamot:**\n" .
                "• **Validamycin 3% L**: **2.0 – 2.5 ml / L** ng tubig (35–40 ml bawat 16L knapsack sprayer).\n" .
                "• **Hexaconazole 5% SC**: **1.5 – 2.0 ml / L** ng tubig.\n" .
                "• **Azoxystrobin + Difenoconazole**: **1.0 ml / L** ng tubig.\n\n" .
                "**2. Pamamahala sa Bukid:**\n" .
                "• **Tamang agwat sa pagtatanim:** Panatilihin ang 20 cm x 20 cm distansya para makasingaw ang hangin.\n" .
                "• **Iwasan ang labis na Urea:** Bawasan ang nitrogen sa panahon ng pagbubuntis.",
            'english' => "### 🌾 Rice Sheath Blight Guide (*Rhizoctonia solani*)\n\n" .
                "**Symptoms:** Oval to irregular greenish-gray water-soaked lesions resembling snake-skin patterns on lower leaf sheaths near the water line.\n\n" .
                "**1. Recommended Fungicides:**\n" .
                "• **Validamycin 3% L**: **2.0 – 2.5 ml / L** (35–40 ml per 16L knapsack sprayer).\n" .
                "• **Hexaconazole 5% SC**: **1.5 – 2.0 ml / L**.\n" .
                "• **Azoxystrobin + Difenoconazole**: **1.0 ml / L**.\n\n" .
                "**2. Agronomic Management:**\n" .
                "• **Plant Spacing:** Maintain 20 cm x 20 cm spacing to improve aeration.\n" .
                "• **Nitrogen Moderation:** Do not over-apply urea during reproductive stages.",
        ],
        'fertilizer' => [
            'tagalog' => "### 🌾 Gabay sa Tamang Pag-aabono ng Palay (DA-PhilRice PalayCheck)\n\n" .
                "**1. Basal Application (0 – 14 Araw Matapos Magtanim):**\n" .
                "• Maglagay ng **Complete Fertilizer (14-14-14)** o **16-20-0** upang mapalakas ang mga ugat.\n" .
                "• Maglagay ng **Zinc Sulfate (25 kg/ha)** kung ang lupa ay kulang sa zinc o maputik.\n\n" .
                "**2. Early Tillering (21 – 28 Araw / Pagsusuwi):**\n" .
                "• Maglagay ng **Urea (46-0-0)** o **Ammonium Sulfate (21-0-0)** batay sa Leaf Color Chart (LCC).\n\n" .
                "**3. Panicle Initiation (40 – 50 Araw / Pagbubuntis):**\n" .
                "• Mag-abono ng **Muriate of Potash (0-0-60 @ 30–40 kg/ha)** kasama ang kalahating sako ng Urea upang maging malaman at mabigat ang butil.",
            'english' => "### 🌾 Rice Crop Fertilization Schedule (DA-PhilRice PalayCheck)\n\n" .
                "**1. Basal Stage (0–14 Days After Transplanting):**\n" .
                "• Apply **Complete (14-14-14)** or **16-20-0** for vigorous root establishment.\n" .
                "• Apply **Zinc Sulfate (25 kg/ha)** in poorly drained soils.\n\n" .
                "**2. Mid-Tillering Stage (21–28 DAT):**\n" .
                "• Apply calibrated **Urea (46-0-0)** guided by the Leaf Color Chart (LCC).\n\n" .
                "**3. Panicle Initiation Stage (40–50 DAT):**\n" .
                "• Topdress **Muriate of Potash (0-0-60 @ 30–40 kg/ha)** mixed with nitrogen to maximize grain filling and weight.",
        ],
        'awd' => [
            'tagalog' => "### 🌾 Patubig: Alternate Wetting and Drying (AWD)\n\n" .
                "Ang **AWD** ay pamamaraan ng DA-PhilRice upang makatipid ng 30% sa tubig at maiwasan ang mga sakit tulad ng Bacterial Leaf Blight at root rot:\n\n" .
                "1. Magbaon ng butas-butas na **Observation Well (PVC pipe)** na may lalim na 15 cm sa putik.\n" .
                "2. Magpatubig ng **3–5 cm** sa ibabaw ng lupa.\n" .
                "3. Hayaang bumaba ang tubig hanggang umabot sa **15 cm sa ilalim ng lupa** bago magpatubig muli.\n" .
                "4. *Paalala:* Panatilihing laging may 3–5 cm na tubig sa panahon ng **pamumulaklak (flowering)** hanggang **paggagatas (milky stage)**.",
            'english' => "### 🌾 Controlled Irrigation: Alternate Wetting and Drying (AWD)\n\n" .
                "**AWD** is a DA-PhilRice water-saving technology that reduces water usage by 30% while aerating roots to prevent BLB and root rot:\n\n" .
                "1. Install a perforated **Field Water Tube (15 cm below soil surface)**.\n" .
                "2. Flood the field to **3–5 cm** ponded depth.\n" .
                "3. Allow water to naturally recede until it drops to **15 cm below soil surface** before re-irrigating.\n" .
                "4. *Important:* Maintain continuous 3–5 cm shallow water during the **flowering and grain-filling stages**.",
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            $messages = ChatMessage::where('user_id', $userId)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($msg) {
                    return [
                        'role' => $msg->role,
                        'content' => $msg->content,
                        'language' => $msg->language,
                        'time' => $msg->created_at->format('g:i A'),
                    ];
                })
                ->all();
        } catch (Exception $e) {
            Log::error('Failed to load consultation messages: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load consultation history.'], 500);
        }

        return response()->json([
            'success' => true,
            'messages' => $messages,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
            'language' => 'required|in:tagalog,english',
        ]);

        $userMessage = trim($request->input('message'));
        $language = $request->input('language');
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // 1. Generate accurate agronomic response first
        $aiResponse = $this->generateResponse($userMessage, $language, $userId);

        // 2. Persist user message and AI response in database
        try {
            $aiMsg = DB::transaction(function () use ($userId, $userMessage, $language, $aiResponse) {
                ChatMessage::create([
                    'user_id' => $userId,
                    'role' => 'user',
                    'content' => $userMessage,
                    'language' => $language,
                ]);

                return ChatMessage::create([
                    'user_id' => $userId,
                    'role' => 'ai',
                    'content' => $aiResponse,
                    'language' => $language,
                ]);
            });
            $time = $aiMsg->created_at->format('g:i A');
        } catch (Exception $e) {
            Log::error('Failed to persist consultation messages: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to save consultation message.'], 500);
        }

        return response()->json([
            'success' => true,
            'ai_response' => $aiResponse,
            'language' => $language,
            'time' => $time,
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            ChatMessage::where('user_id', $userId)->delete();
        } catch (Exception $e) {
            Log::error('Failed to clear consultation messages: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to clear consultation history.'], 500);
        }
        return response()->json(['success' => true]);
    }

    private function generateResponse(string $message, string $language, ?int $userId = null): string
    {
        // 1. Primary: Google Gemini API with PhilRice domain grounding
        $geminiResponse = $this->callGeminiApi($message, $language, $userId);
        if (!empty($geminiResponse)) {
            return $geminiResponse;
        }

        // 2. Dynamic Chatbot Knowledge Base (Admin Managed)
        $msg = strtolower($message);
        try {
            $knowledgeItems = ChatbotKnowledge::where('status', 'active')
                ->where(function($q) use ($language) {
                    $q->where('language', $language)->orWhere('language', 'all');
                })
                ->get();

            foreach ($knowledgeItems as $item) {
                $itemQ = strtolower($item->question);
                $qWords = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/i', '', $itemQ)), fn($w) => strlen($w) > 3);
                $matches = 0;
                $totalWords = count($qWords);
                foreach ($qWords as $qw) {
                    if (str_contains($msg, $qw)) $matches++;
                }
                if (($totalWords > 0 && ($matches / $totalWords) >= 0.5) || str_contains($msg, $itemQ)) {
                    return $item->answer;
                }
            }
        } catch (Exception $e) {
        }

        // 3. Robust Agronomic Fallback Matcher
        if (str_contains($msg, 'blast') || str_contains($msg, 'amag') || str_contains($msg, 'magnaporthe')) {
            return $this->diseaseResponses['blast'][$language] ?? $this->diseaseResponses['blast']['tagalog'];
        }

        if (str_contains($msg, 'blight') || str_contains($msg, 'blb') || str_contains($msg, 'xanthomonas') || str_contains($msg, 'bacterial')) {
            return $this->diseaseResponses['blight'][$language] ?? $this->diseaseResponses['blight']['tagalog'];
        }

        if (str_contains($msg, 'brown') || str_contains($msg, 'bipolaris') || str_contains($msg, 'spot') || str_contains($msg, 'batik')) {
            return $this->diseaseResponses['brown'][$language] ?? $this->diseaseResponses['brown']['tagalog'];
        }

        if (str_contains($msg, 'tungro') || str_contains($msg, 'leafhopper') || str_contains($msg, 'glh') || str_contains($msg, 'nangangagat') || str_contains($msg, 'orange') || str_contains($msg, 'dilaw')) {
            return $this->diseaseResponses['tungro'][$language] ?? $this->diseaseResponses['tungro']['tagalog'];
        }

        if (str_contains($msg, 'sheath') || str_contains($msg, 'rhizoctonia') || str_contains($msg, 'saha')) {
            return $this->diseaseResponses['sheath'][$language] ?? $this->diseaseResponses['sheath']['tagalog'];
        }

        if (str_contains($msg, 'abono') || str_contains($msg, 'pataba') || str_contains($msg, 'fertilizer') || str_contains($msg, 'urea') || str_contains($msg, 'potash') || str_contains($msg, 'zinc') || str_contains($msg, 'complete') || str_contains($msg, '14-14-14')) {
            return $this->diseaseResponses['fertilizer'][$language] ?? $this->diseaseResponses['fertilizer']['tagalog'];
        }

        if (str_contains($msg, 'tubig') || str_contains($msg, 'patubig') || str_contains($msg, 'awd') || str_contains($msg, 'water') || str_contains($msg, 'baha') || str_contains($msg, 'tuyo')) {
            return $this->diseaseResponses['awd'][$language] ?? $this->diseaseResponses['awd']['tagalog'];
        }

        if ($language === 'tagalog') {
            return "Magandang araw! Ako po ang **Oryzatix AI Agronomist**, ang inyong katuwang sa siyentipiko at praktikal na pamamahala ng palayan ayon sa pamantayan ng **DA-PhilRice** at **IRRI**.\n\n" .
                "Maaari po kayong magtanong tungkol sa:\n" .
                "• **Mga Sakit ng Palay:** Bacterial Leaf Blight (BLB), Leaf Blast, Rice Tungro, Brown Spot, at Sheath Blight.\n" .
                "• **Mga Gamot at Peste:** Fungicides, Insecticides laban sa Green Leafhopper/Stem Borer, tamang dosage bawat 16L sprayer.\n" .
                "• **Tamang Pag-aabono at Sustansya:** Iskedyul ng Basal (14-14-14), Urea (46-0-0), Potash (0-0-60), Zinc Sulfate, at Leaf Color Chart (LCC).\n" .
                "• **Patubig at Pamamahala:** Alternate Wetting and Drying (AWD) at certified inbred/hybrid seed varieties.\n\n" .
                "Ano po ang partikular na sitwasyon o katanungan ninyo sa inyong palayan?";
        }

        return "Greetings! I am the **Oryzatix AI Agronomist**, your intelligent agricultural assistant grounded in **DA-PhilRice** and **IRRI** rice production standards.\n\n" .
            "You can consult me on:\n" .
            "• **Rice Leaf Diseases:** Bacterial Leaf Blight (BLB), Rice Leaf Blast, Rice Tungro Disease, Brown Spot, and Sheath Blight.\n" .
            "• **Pesticides & Dosages:** Approved active ingredients, fungicides, bactericides, and knapsack spray calibrations.\n" .
            "• **Fertilizer & Soil Management:** Basal NPK, Urea topdressing, Potassium (MOP), Zinc deficiency solutions, and Leaf Color Chart (LCC).\n" .
            "• **Water & Agronomy:** Alternate Wetting and Drying (AWD) and recommended certified seeds.\n\n" .
            "How may I assist you with your rice crop today?";
    }

    /**
     * Call Google Gemini REST API with domain-grounded agronomic prompt.
     */
    private function callGeminiApi(string $userMessage, string $language, ?int $userId = null): ?string
    {
        $apiKey = config('services.gemini.api_key') ?: env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            return null;
        }

        $systemPrompt = $this->buildGeminiSystemPrompt($language);

        // Fetch recent conversation history and construct strict alternating multiturn contents
        $contents = [];
        if ($userId) {
            try {
                $pastMessages = ChatMessage::where('user_id', $userId)
                    ->orderBy('created_at', 'desc')
                    ->limit(6)
                    ->get()
                    ->reverse();

                $lastRole = null;
                foreach ($pastMessages as $msg) {
                    $turnRole = $msg->role === 'ai' ? 'model' : 'user';
                    // Skip consecutive duplicate roles to guarantee strict alternating requirement
                    if ($turnRole === $lastRole) {
                        continue;
                    }
                    $contents[] = [
                        'role' => $turnRole,
                        'parts' => [
                            ['text' => (string)$msg->content]
                        ]
                    ];
                    $lastRole = $turnRole;
                }
            } catch (Exception $e) {
            }
        }

        // Ensure history doesn't end with a user turn before adding current message
        if (!empty($contents) && end($contents)['role'] === 'user') {
            array_pop($contents);
        }

        // Add current user message
        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $userMessage]
            ]
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt]
                ]
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.2,
                'topP' => 0.85,
                'topK' => 40,
                'maxOutputTokens' => 2048,
            ]
        ];

        $configuredModel = config('services.gemini.model') ?: env('GEMINI_MODEL', 'gemini-flash-lite-latest');
        $modelsToTry = array_unique([$configuredModel, 'gemini-flash-lite-latest', 'gemini-3.1-flash-lite', 'gemini-3.8-flash', 'gemini-3.5-flash-lite', 'gemini-flash-latest']);

        foreach ($modelsToTry as $mName) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$mName}:generateContent?key={$apiKey}";

                $response = Http::timeout(15)->post($url, $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $candidates = $data['candidates'] ?? [];
                    if (!empty($candidates)) {
                        $parts = $candidates[0]['content']['parts'] ?? [];
                        if (!empty($parts)) {
                            $reply = trim($parts[0]['text'] ?? '');
                            if (!empty($reply)) {
                                return $reply;
                            }
                        }
                    }
                } else {
                    Log::warning("Gemini API ($mName) status: " . $response->status() . ' body: ' . $response->body());
                }
            } catch (Exception $e) {
                Log::error("Failed calling Google Gemini API ($mName): " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Build strict domain-restricted system prompt for Oryzatix Rice Disease & Agronomy Consultation.
     */
    private function buildGeminiSystemPrompt(string $language): string
    {
        return <<<PROMPT
You are "Oryzatix AI Agronomist" (Oryzatix AI Konsultasyon), an expert agricultural and crop protection specialist dedicated EXCLUSIVELY to rice farming (Oryza sativa) in the Philippines, adhering strictly to Department of Agriculture - Philippine Rice Research Institute (DA-PhilRice) and International Rice Research Institute (IRRI) PalayCheck standards.

=======================================================
1. SCOPE & DOMAIN EXPERTISE (RICE FARMING ONLY):
=======================================================
You provide authoritative, practical, and highly accurate answers on:
1. RICE DISEASES & SYMPTOMS:
   - Bacterial Leaf Blight (BLB) (*Xanthomonas oryzae pv. oryzae*)
   - Rice Tungro Disease (RTBV/RTSV virus transmitted by Green Leafhopper *Nephotettix virescens*)
   - Rice Brown Spot (*Bipolaris oryzae / Cochliobolus miyabeanus*)
   - Rice Leaf Blast & Neck Blast (*Magnaporthe oryzae*)
   - Sheath Blight (*Rhizoctonia solani*)
   - False Smut, Bakanae, and Narrow Brown Leaf Spot.
2. PEST MANAGEMENT & VECTORS:
   - Green Leafhopper (GLH), Brown Planthopper (BPH), Yellow Stem Borer, Rice Bug (Atangya / *Leptocorisa acuta*), Rice Hispa, Armyworms.
3. SOIL NUTRIENTS & FERTILIZER SCHEDULE (PalayCheck System):
   - Basal: Complete 14-14-14 or 16-20-0 for root vigor.
   - Early Tillering: Urea 46-0-0 / Ammonium Sulfate 21-0-0 calibrated with Leaf Color Chart (LCC).
   - Panicle Initiation: Muriate of Potash (0-0-60 @ 30–40 kg/ha) for grain density and cell-wall disease defense.
   - Zinc Deficiency: Zinc Sulfate (25 kg/ha) for reddish-brown/rusty leaves in flooded soil.
4. WATER & CULTURAL MANAGEMENT:
   - Alternate Wetting and Drying (AWD) irrigation technology using observation wells.
   - Certified seed varieties (e.g., NSIC Rc222, PSB Rc82, NSIC Rc160, PSB Rc18, NSIC Rc152).
   - Crop sanitation, synchronous community planting, and rogueing of viral crops.
5. DOSAGES & SEVERITY-CALIBRATED TREATMENTS:
   - Mild (≤ 25%), Moderate (26%–60%), Severe (> 60%).
   - Always state both concentrations (per Liter) and practical farmer measurements (per 16-Liter Knapsack Sprayer).

=======================================================
2. STRICT GUARDRAIL & REFUSAL POLICY:
=======================================================
If the user asks about ANY topic NOT related to rice agriculture, rice pests, rice soil/fertilizers, water management, or farming (such as coding, general math, movies, celebrities, politics, gaming, non-agricultural homework, or non-rice plants like mango, corn, tomato, banana):
You MUST POLITELY REFUSE:
- In Tagalog: "Paumanhin po, ako po ay si Oryzatix AI Agronomist na nakalaan lamang para sumagot sa mga katanungan tungkol sa pagsasaka ng palay, mga sakit at peste ng palay, tamang abono, at mga pamantayan ng DA-PhilRice. May maitutulong po ba ako tungkol sa inyong palayan?"
- In English: "I apologize, but I am the Oryzatix AI Agronomist dedicated exclusively to rice farming, rice crop diseases, pest management, fertilization, and DA-PhilRice agronomic standards. How can I assist you with your rice crops today?"

=======================================================
3. RESPONSE FORMAT & TONE:
=======================================================
• Language: Respond fluently and respectfully in {$language}. (If tagalog, use warm, respectful, and natural Filipino farmer terms like Ka-Oryzatix, pagsusuwi, pagbubuntis, pilapil, patubig, knapsack sprayer).
• Structure:
  - Start with a clear, direct summary of the diagnosis/answer.
  - Use bold headings (e.g. ### 🌾 Pamamahala at Gamot).
  - Use bullet points (•) for chemical active ingredients, exact dosages (e.g., **2.0–2.5 g / L** o **30–40 g bawat 16L sprayer**), and safety intervals.
  - Provide cultural/preventive practices (sanitation, fertilization adjustment, water drainage).
• Completeness: Never leave sentences unfinished or truncated. Provide full, step-by-step guidance.
PROMPT;
    }

    /**
     * Translate consultation text between Tagalog and English.
     */
    public function translate(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string',
            'target_language' => 'required|in:tagalog,english',
        ]);

        $text = trim($request->input('text'));
        $targetLang = $request->input('target_language');

        $translated = $this->callGeminiTranslation($text, $targetLang);

        if (!empty($translated)) {
            return response()->json([
                'success' => true,
                'translated_text' => $translated,
                'target_language' => $targetLang,
            ]);
        }

        return response()->json([
            'success' => true,
            'translated_text' => $text,
            'target_language' => $targetLang,
            'message' => 'Translation unavailable, showing original message.',
        ]);
    }

    /**
     * Translate text accurately using Google Gemini API.
     */
    private function callGeminiTranslation(string $text, string $targetLang): ?string
    {
        $apiKey = config('services.gemini.api_key') ?: env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            return null;
        }

        $langTarget = $targetLang === 'tagalog' ? 'natural Tagalog / Filipino' : 'fluent English';
        $instruction = "You are a professional agricultural translator. Accurately translate the following rice consultation advice into {$langTarget}. Retain all markdown formatting, bullet points, headings, chemical dosages, and active ingredients exactly. Output ONLY the translated text.";

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $instruction]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $text]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 2048,
            ]
        ];

        $configuredModel = config('services.gemini.model') ?: env('GEMINI_MODEL', 'gemini-flash-lite-latest');
        $modelsToTry = array_unique([$configuredModel, 'gemini-flash-lite-latest', 'gemini-3.1-flash-lite', 'gemini-3.8-flash', 'gemini-flash-latest']);

        foreach ($modelsToTry as $mName) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$mName}:generateContent?key={$apiKey}";
                $response = Http::timeout(15)->post($url, $payload);
                if ($response->successful()) {
                    $data = $response->json();
                    $candidates = $data['candidates'] ?? [];
                    if (!empty($candidates)) {
                        $parts = $candidates[0]['content']['parts'] ?? [];
                        if (!empty($parts)) {
                            $reply = trim($parts[0]['text'] ?? '');
                            if (!empty($reply)) {
                                return $reply;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                Log::error("Gemini Translation ($mName) error: " . $e->getMessage());
            }
        }

        return null;
    }
}
